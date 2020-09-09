<?php
/**
 * @link https://github.com/illuminatech
 * @copyright Copyright (c) 2019 Illuminatech
 * @license [New BSD License](http://www.opensource.org/licenses/bsd-license.php)
 */

namespace Illuminatech\MultipartMiddleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Arr;
use Symfony\Component\HttpFoundation\FileBag;
use Symfony\Component\HttpFoundation\ParameterBag;

/**
 * MultipartFormDataParser is a middleware for parsing 'multipart/form-data' HTTP requests.
 *
 * This middleware provides ability to parse 'multipart/form-data' HTTP requests for any request method, including 'PUT',
 * 'PATCH' and so on without necessity to spoof it using '_method' parameter.
 *
 * This middleware should be applied to your HTTP kernel prior to any other middleware, which operates input data.
 * For example:
 *
 * ```php
 * namespace App\Http;
 *
 * use Illuminate\Foundation\Http\Kernel as HttpKernel;
 *
 * class Kernel extends HttpKernel
 * {
 *     protected $middleware = [
 *         \App\Http\Middleware\CheckForMaintenanceMode::class,
 *         \Illuminate\Foundation\Http\Middleware\ValidatePostSize::class,
 *         \Illuminatech\MultipartMiddleware\MultipartFormDataParser::class, // parse multipart request, before operating input
 *         \App\Http\Middleware\TrimStrings::class,
 *         \Illuminate\Foundation\Http\Middleware\ConvertEmptyStringsToNull::class,
 *         // ...
 *     ];
 *     // ...
 * }
 * ```
 *
 * > Note: although this parser populates temporary file name for the uploaded file instance, such temporary file will
 * not be recognized by PHP as uploaded one. Thus functions like `is_uploaded_file()` and `move_uploaded_file()` will
 * fail on it. Thus all created uploaded file instances are marked as test ones.
 *
 * > Attention: all created temporary files will be automatically deleted, once middleware instance is destroyed.
 *
 * @author Paul Klimov <klimov.paul@gmail.com>
 * @since 1.0
 */
class MultipartFormDataParser
{
    /**
     * @var int upload file max size in bytes.
     */
    private $uploadFileMaxSize;

    /**
     * @var int maximum upload files count.
     */
    private $uploadFileMaxCount;

    /**
     * @var resource[] resources for temporary file, created during request parsing.
     */
    private $tmpFileResources = [];

    /**
     * @return int upload file max size in bytes.
     */
    public function getUploadFileMaxSize(): int
    {
        if ($this->uploadFileMaxSize === null) {
            $this->uploadFileMaxSize = UploadedFile::getMaxFilesize();
        }

        return $this->uploadFileMaxSize;
    }
    /**
     * @param  int  $uploadFileMaxSize upload file max size in bytes.
     * @return static self reference.
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
     * @return static self reference.
     */
    public function setUploadFileMaxCount(int $uploadFileMaxCount): self
    {
        $this->uploadFileMaxCount = $uploadFileMaxCount;

        return $this;
    }

    /**
     * Handle an incoming request, performing its 'multipart/form-data' content parsing if necessary.
     *
     * @param  \Illuminate\Http\Request  $request request to be processed.
     * @param  \Closure  $next next pipeline request handler.
     * @param  bool  $force whether to parse raw body even for 'POST' request and `Request::$files` are already populated.
     * @return mixed
     */
    public function handle(Request $request, Closure $next, $force = false)
    {
        if (! $force) {
            if ($request->getRealMethod() === 'POST' || count($request->files) > 0) {
                // normal POST request is parsed by PHP automatically
                return $next($request);
            }
        }

        return $next($this->parse($request));
    }

    /**
     * Parses given request in case it holds 'multipart/form-data' content.
     * This method is immutable: it leaves passed request object intact, creating new one for parsed results.
     * This method returns original request in case it does not hold appropriate content type or has empty body.
     *
     * @param  \Illuminate\Http\Request  $request request to be parsed.
     * @return \Illuminate\Http\Request parsed request.
     */
    public function parse(Request $request): Request
    {
        $contentType = $request->headers->get('CONTENT_TYPE');
        if (stripos($contentType, 'multipart/form-data') === false) {
            return $request;
        }
        if (! preg_match('/boundary=(.*)$/is', $contentType, $matches)) {
            return $request;
        }
        $boundary = $matches[1];

        $rawBody = $request->getContent();
        if (empty($rawBody)) {
            return $request;
        }

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

            if (! isset($headers['content-disposition']['name'])) {
                continue;
            }

            if (isset($headers['content-disposition']['filename'])) {
                // file upload:
                if ($filesCount >= $this->getUploadFileMaxCount()) {
                    continue;
                }

                $clientFilename = $headers['content-disposition']['filename'];
                $clientMediaType = Arr::get($headers, 'content-type', 'application/octet-stream');
                $size = mb_strlen($value, '8bit');
                $error = UPLOAD_ERR_OK;
                $tempFilename = '';

                if ($size > $this->getUploadFileMaxSize()) {
                    $error = UPLOAD_ERR_INI_SIZE;
                } else {
                    $tmpResource = tmpfile();

                    if ($tmpResource === false) {
                        $error = UPLOAD_ERR_CANT_WRITE;
                    } else {
                        $tmpResourceMetaData = stream_get_meta_data($tmpResource);
                        $tmpFileName = $tmpResourceMetaData['uri'];

                        if (empty($tmpFileName)) {
                            $error = UPLOAD_ERR_CANT_WRITE;
                            @fclose($tmpResource);
                        } else {
                            fwrite($tmpResource, $value);
                            $tempFilename = $tmpFileName;
                            $this->tmpFileResources[] = $tmpResource; // save file resource, otherwise it will be deleted
                        }
                    }
                }

                $this->addValue(
                    $uploadedFiles,
                    $headers['content-disposition']['name'],
                    $this->createUploadedFile(
                        $tempFilename,
                        $clientFilename,
                        $clientMediaType,
                        $error
                    )
                );

                $filesCount++;
            } else {
                // regular parameter:
                $this->addValue($bodyParams, $headers['content-disposition']['name'], $value);
            }
        }

        return $this->newRequest($request, $bodyParams, $uploadedFiles);
    }

    /**
     * Creates new request instance from original one with parsed body parameters and uploaded files.
     * This method is called only in case original request has been successfully parsed as 'multipart/form-data'.
     *
     * @param  \Illuminate\Http\Request  $originalRequest original request instance being parsed.
     * @param  array  $bodyParams parsed body parameters.
     * @param  array  $uploadedFiles parsed uploaded files.
     * @return \Illuminate\Http\Request new request instance.
     */
    protected function newRequest(Request $originalRequest, array $bodyParams, array $uploadedFiles): Request
    {
        $request = clone $originalRequest;

        $request->request = new ParameterBag($bodyParams);
        $request->files = new FileBag($uploadedFiles);

        return $request;
    }

    /**
     * Creates new uploaded file instance.
     *
     * @param  string  $tempFilename the full temporary path to the file.
     * @param  string  $clientFilename the filename sent by the client.
     * @param  string|null  $clientMediaType the media type sent by the client.
     * @param  int|null  $error the error associated with the uploaded file.
     * @return \Symfony\Component\HttpFoundation\File\UploadedFile|object new uploaded file instance.
     */
    protected function createUploadedFile(string $tempFilename, string $clientFilename, string $clientMediaType = null, int $error = null)
    {
        return new UploadedFile($tempFilename, $clientFilename, $clientMediaType, $error, true);
    }

    /**
     * Parses content part headers.
     *
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
     *
     * @param  array  $array array which should store value.
     * @param  string  $name input name specification.
     * @param mixed $value value to be added.
     */
    private function addValue(&$array, $name, $value): void
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
                if (! isset($current[$namePart])) {
                    $current[$namePart] = [];
                }
                $current = &$current[$namePart];
            }
        }
        $current = $value;
    }

    /**
     * Closes all temporary files associated with this parser instance.
     *
     * @return static self instance.
     */
    public function closeTmpFiles(): self
    {
        foreach ($this->tmpFileResources as $resource) {
            @fclose($resource);
        }

        $this->tmpFileResources = [];

        return $this;
    }

    /**
     * Destructor.
     * Ensures all possibly created during parsing temporary files are gracefully closed and removed.
     */
    public function __destruct()
    {
        $this->closeTmpFiles();
    }
}
