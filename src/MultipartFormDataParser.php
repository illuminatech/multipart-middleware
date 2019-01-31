<?php
/**
 * @link https://github.com/illuminatech
 * @copyright Copyright (c) 2015 Illuminatech
 * @license [New BSD License](http://www.opensource.org/licenses/bsd-license.php)
 */

namespace Illuminatech\MultipartMiddleware;

use Closure;
use Illuminate\Support\Arr;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\FileBag;
use Symfony\Component\HttpFoundation\ParameterBag;
use Symfony\Component\HttpFoundation\File\UploadedFile;

/**
 * MultipartFormDataParser
 *
 * @author Paul Klimov <klimov.paul@gmail.com>
 * @since 1.0
 */
class MultipartFormDataParser
{
    /**
     * @var bool whether to parse raw body even for 'POST' request and `Request::$files` already populated.
     * By default this option is disabled saving performance for 'POST' requests, which are already
     * processed by PHP automatically.
     */
    public $force = false;

    /**
     * @var int upload file max size in bytes.
     */
    private $uploadFileMaxSize;

    /**
     * @var int maximum upload files count.
     */
    private $uploadFileMaxCount;

    /**
     * @var resource[]
     */
    private $tmpFileResources = [];

    /**
     * @return int upload file max size in bytes.
     */
    public function getUploadFileMaxSize(): int
    {
        if ($this->uploadFileMaxSize === null) {
            $this->uploadFileMaxSize = $this->getByteSize(ini_get('upload_max_filesize'));
        }

        return $this->uploadFileMaxSize;
    }
    /**
     * @param  int  $uploadFileMaxSize upload file max size in bytes.
     * @return static self reference
     */
    public function setUploadFileMaxSize(int $uploadFileMaxSize): self
    {
        $this->uploadFileMaxSize = $uploadFileMaxSize;

        return $this;
    }
    /**
     * @return int maximum upload files count.
     */
    public function getUploadFileMaxCount(): int
    {
        if ($this->uploadFileMaxCount === null) {
            $this->uploadFileMaxCount = ini_get('max_file_uploads');
        }

        return $this->uploadFileMaxCount;
    }
    /**
     * @param int $uploadFileMaxCount maximum upload files count.
     * @return static self reference
     */
    public function setUploadFileMaxCount(int $uploadFileMaxCount): self
    {
        $this->uploadFileMaxCount = $uploadFileMaxCount;

        return $this;
    }

    /**
     * Handle an incoming request.
     *
     * @param  \Illuminate\Http\Request  $request
     * @param  \Closure  $next
     * @return mixed
     */
    public function handle(Request $request, Closure $next)
    {
        if (!$this->force) {
            if ($request->getRealMethod() === 'POST' || count($request->files) > 0) {
                // normal POST request is parsed by PHP automatically
                return $next($request);
            }
        }

        return $next($this->parse($request));
    }

    public function parse(Request $request): Request
    {
        $contentType = $request->headers->get('CONTENT_TYPE');
        if (stripos($contentType, 'multipart/form-data') === false) {
            return $request;
        }
        if (!preg_match('/boundary=(.*)$/is', $contentType, $matches)) {
            return $request;
        }
        $boundary = $matches[1];

        $rawBody = $request->getContent();
        if (empty($rawBody)) {
            return $request;
        }

        $request = clone $request;

        $bodyParts = preg_split('/\\R?-+' . preg_quote($boundary, '/') . '/s', $rawBody);
        array_pop($bodyParts); // last block always has no data, contains boundary ending like `--`

        $bodyParams = [];
        $uploadedFiles = [];
        $filesCount = 0;
        foreach ($bodyParts as $bodyPart) {
            if (empty($bodyPart)) {
                continue;
            }
            [$headers, $value] = preg_split('/\\R\\R/', $bodyPart, 2);
            $headers = $this->parseHeaders($headers);

            if (!isset($headers['content-disposition']['name'])) {
                continue;
            }

            if (isset($headers['content-disposition']['filename'])) {
                // file upload:
                if ($filesCount >= $this->getUploadFileMaxCount()) {
                    continue;
                }

                $fileConfig = [
                    'clientFilename' => $headers['content-disposition']['filename'],
                    'clientMediaType' => Arr::get($headers, 'content-type', 'application/octet-stream'),
                    'size' => mb_strlen($value, '8bit'),
                    'error' => UPLOAD_ERR_OK,
                    'tempFilename' => '',
                ];

                if ($fileConfig['size'] > $this->getUploadFileMaxSize()) {
                    $fileConfig['error'] = UPLOAD_ERR_INI_SIZE;
                } else {
                    $tmpResource = tmpfile();

                    if ($tmpResource === false) {
                        $fileConfig['error'] = UPLOAD_ERR_CANT_WRITE;
                    } else {
                        $tmpResourceMetaData = stream_get_meta_data($tmpResource);
                        $tmpFileName = $tmpResourceMetaData['uri'];

                        if (empty($tmpFileName)) {
                            $fileConfig['error'] = UPLOAD_ERR_CANT_WRITE;
                            @fclose($tmpResource);
                        } else {
                            fwrite($tmpResource, $value);
                            $fileConfig['tempFilename'] = $tmpFileName;
                            $this->tmpFileResources[] = $tmpResource; // save file resource, otherwise it will be deleted
                        }
                    }
                }
                $this->addValue($uploadedFiles, $headers['content-disposition']['name'], $this->createUploadedFile($fileConfig));
                $filesCount++;
            } else {
                // regular parameter:
                $this->addValue($bodyParams, $headers['content-disposition']['name'], $value);
            }
        }

        $request->request = new ParameterBag($bodyParams);
        $request->files = new FileBag($uploadedFiles);

        return $request;
    }

    /**
     * Parses content part headers.
     * @param  string  $headerContent headers source content
     * @return array parsed headers.
     */
    private function parseHeaders(string $headerContent): array
    {
        $headers = [];
        $headerParts = preg_split('/\\R/s', $headerContent, -1, PREG_SPLIT_NO_EMPTY);
        foreach ($headerParts as $headerPart) {
            if (strpos($headerPart, ':') === false) {
                continue;
            }

            [$headerName, $headerValue] = explode(':', $headerPart, 2);
            $headerName = strtolower(trim($headerName));
            $headerValue = trim($headerValue);

            if (strpos($headerValue, ';') === false) {
                $headers[$headerName] = $headerValue;
            } else {
                $headers[$headerName] = [];
                foreach (explode(';', $headerValue) as $part) {
                    $part = trim($part);
                    if (strpos($part, '=') === false) {
                        $headers[$headerName][] = $part;
                    } else {
                        [$name, $value] = explode('=', $part, 2);
                        $name = strtolower(trim($name));
                        $value = trim(trim($value), '"');
                        $headers[$headerName][$name] = $value;
                    }
                }
            }
        }

        return $headers;
    }

    /**
     * Adds value to the array by input name, e.g. `Item[name]`.
     * @param  array  $array array which should store value.
     * @param  string  $name input name specification.
     * @param mixed $value value to be added.
     */
    private function addValue(&$array, $name, $value)
    {
        $nameParts = preg_split('/\\]\\[|\\[/s', $name);
        $current = &$array;
        foreach ($nameParts as $namePart) {
            $namePart = trim($namePart, ']');
            if ($namePart === '') {
                $current[] = [];
                $keys = array_keys($current);
                $lastKey = array_pop($keys);
                $current = &$current[$lastKey];
            } else {
                if (!isset($current[$namePart])) {
                    $current[$namePart] = [];
                }
                $current = &$current[$namePart];
            }
        }
        $current = $value;
    }

    private function createUploadedFile(array $config)
    {
        return new UploadedFile($config['tempFilename'], $config['clientFilename'], $config['clientMediaType'], $config['error'], true);
    }

    /**
     * Gets the size in bytes from verbose size representation.
     *
     * For example: '5K' => 5*1024.
     * @param  string  $verboseSize verbose size representation.
     * @return int actual size in bytes.
     */
    private function getByteSize($verboseSize): int
    {
        if (empty($verboseSize)) {
            return 0;
        }

        if (is_numeric($verboseSize)) {
            return (int) $verboseSize;
        }

        $sizeUnit = trim($verboseSize, '0123456789');
        $size = trim(str_replace($sizeUnit, '', $verboseSize));
        if (!is_numeric($size)) {
            return 0;
        }

        switch (strtolower($sizeUnit)) {
            case 'kb':
            case 'k':
                return $size * 1024;
            case 'mb':
            case 'm':
                return $size * 1024 * 1024;
            case 'gb':
            case 'g':
                return $size * 1024 * 1024 * 1024;
            default:
                return 0;
        }
    }
}
