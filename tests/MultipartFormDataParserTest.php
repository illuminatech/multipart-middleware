<?php

namespace Illuminatech\MultipartMiddleware\Test;

use Closure;
use Illuminate\Http\Request;
use Illuminatech\MultipartMiddleware\MultipartFormDataParser;
use Symfony\Component\HttpFoundation\File\UploadedFile;

class MultipartFormDataParserTest extends TestCase
{
    /**
     * Creates test HTTP request instance.
     * @param  array  $headers
     * @param  string  $rawBody
     * @param  string  $method
     * @return Request
     */
    protected function createRequest(array $headers, string $rawBody, string $method = 'PUT'): Request
    {
        $request = Request::create('http://example.test', $method, [], [], [], [], $rawBody);
        $request->headers->add($headers);

        return $request;
    }

    /**
     * Creates dummy request handler for the middleware.
     * @return Closure
     */
    protected function createDummyRequestHandler(): Closure
    {
        return function (Request $request) {
            return $request;
        };
    }

    public function testParse()
    {
        $parser = new MultipartFormDataParser();

        $boundary = '---------------------------22472926011618';
        $contentType = 'multipart/form-data; boundary=' . $boundary;
        $rawBody = "--{$boundary}\nContent-Disposition: form-data; name=\"title\"\r\n\r\ntest-title";
        $rawBody .= "\r\n--{$boundary}\nContent-Disposition: form-data; name=\"Item[name]\"\r\n\r\ntest-name";
        $rawBody .= "\r\n--{$boundary}\nContent-Disposition: form-data; name=\"someFile\"; filename=\"some-file.txt\"\nContent-Type: text/plain\r\n\r\nsome file content";
        $rawBody .= "\r\n--{$boundary}\nContent-Disposition: form-data; name=\"Item[file]\"; filename=\"item-file.txt\"\nContent-Type: text/plain\r\n\r\nitem file content";
        $rawBody .= "\r\n--{$boundary}--";

        $request = $this->createRequest(['content-type' => [$contentType]], $rawBody);

        $parsedRequest = $parser->parse($request);

        $this->assertNotSame($request, $parsedRequest);

        $bodyParams = $parsedRequest->request->all();
        $expectedBodyParams = [
            'title' => 'test-title',
            'Item' => [
                'name' => 'test-name',
            ],
        ];
        $this->assertEquals($expectedBodyParams, $bodyParams);

        $uploadedFiles = $parsedRequest->files->all();

        $this->assertFalse(empty($uploadedFiles['someFile']));
        /* @var $uploadedFile UploadedFile */
        $uploadedFile = $uploadedFiles['someFile'];
        $this->assertTrue($uploadedFile instanceof UploadedFile);
        $this->assertEquals(UPLOAD_ERR_OK, $uploadedFile->getError());
        $this->assertEquals('some-file.txt', $uploadedFile->getClientOriginalName());
        $this->assertEquals('text/plain', $uploadedFile->getClientMimeType());
        $this->assertEquals('some file content', file_get_contents($uploadedFile->getPathname()));

        $this->assertFalse(empty($uploadedFiles['Item']['file']));
        /* @var $uploadedFile UploadedFile */
        $uploadedFile = $uploadedFiles['Item']['file'];
        $this->assertEquals(UPLOAD_ERR_OK, $uploadedFile->getError());
        $this->assertEquals('item-file.txt', $uploadedFile->getClientOriginalName());
        $this->assertEquals('text/plain', $uploadedFile->getClientMimeType());
        $this->assertEquals('item file content', file_get_contents($uploadedFile->getPathname()));
    }

    /**
     * @depends testParse
     */
    public function testUploadFileMaxCount()
    {
        $parser = new MultipartFormDataParser();
        $parser->setUploadFileMaxCount(2);

        $boundary = '---------------------------22472926011618';
        $contentType = 'multipart/form-data; boundary=' . $boundary;
        $rawBody = "--{$boundary}\nContent-Disposition: form-data; name=\"firstFile\"; filename=\"first-file.txt\"\nContent-Type: text/plain\r\n\r\nfirst file content";
        $rawBody .= "--{$boundary}\nContent-Disposition: form-data; name=\"secondFile\"; filename=\"second-file.txt\"\nContent-Type: text/plain\r\n\r\nsecond file content";
        $rawBody .= "--{$boundary}\nContent-Disposition: form-data; name=\"thirdFile\"; filename=\"third-file.txt\"\nContent-Type: text/plain\r\n\r\nthird file content";
        $rawBody .= "--{$boundary}--";

        $request = $this->createRequest(['content-type' => [$contentType]], $rawBody);

        $parsedRequest = $parser->parse($request);
        $this->assertCount(2, $parsedRequest->files);
    }

    /**
     * @depends testParse
     */
    public function testUploadFileMaxSize()
    {
        $parser = new MultipartFormDataParser();
        $parser->setUploadFileMaxSize(20);

        $boundary = '---------------------------22472926011618';
        $contentType = 'multipart/form-data; boundary=' . $boundary;
        $rawBody = "--{$boundary}\nContent-Disposition: form-data; name=\"firstFile\"; filename=\"first-file.txt\"\nContent-Type: text/plain\r\n\r\nfirst file content";
        $rawBody .= "--{$boundary}\nContent-Disposition: form-data; name=\"secondFile\"; filename=\"second-file.txt\"\nContent-Type: text/plain\r\n\r\nsecond file content";
        $rawBody .= "--{$boundary}\nContent-Disposition: form-data; name=\"thirdFile\"; filename=\"third-file.txt\"\nContent-Type: text/plain\r\n\r\nthird file with too long file content";
        $rawBody .= "--{$boundary}--";

        $request = $this->createRequest(['content-type' => [$contentType]], $rawBody);
        $parsedRequest = $parser->parse($request);

        $uploadedFiles = $parsedRequest->files->all();
        $this->assertCount(3, $uploadedFiles);
        $this->assertEquals(UPLOAD_ERR_INI_SIZE, $uploadedFiles['thirdFile']->getError());
    }

    /**
     * @depends testParse
     */
    public function testSkipPost()
    {
        $request = $this->createRequest(
            ['content-type' => ['multipart/form-data; boundary=---12345']],
            'should not matter',
            'POST'
        );

        $parser = new MultipartFormDataParser();

        $handledRequest = $parser->handle($request, $this->createDummyRequestHandler());

        $this->assertSame($request, $handledRequest);
    }

    /**
     * @depends testParse
     */
    public function testSkipNotEmptyFiles()
    {
        $request = $this->createRequest(
            ['content-type' => ['multipart/form-data; boundary=---12345']],
            'should not matter',
            'PUT'
        );

        $request->files->set('file', new UploadedFile(__FILE__, 'test.txt'));

        $parser = new MultipartFormDataParser();

        $handledRequest = $parser->handle($request, $this->createDummyRequestHandler());

        $this->assertSame($request, $handledRequest);
    }

    /**
     * @depends testSkipPost
     * @depends testSkipNotEmptyFiles
     */
    public function testForce()
    {
        $parser = new MultipartFormDataParser();

        $boundary = '---------------------------22472926011618';
        $contentType = 'multipart/form-data; boundary=' . $boundary;
        $rawBody = "--{$boundary}\nContent-Disposition: form-data; name=\"title\"\r\n\r\ntest-title";
        $rawBody .= "\r\n--{$boundary}\nContent-Disposition: form-data; name=\"someFile\"; filename=\"some-file.txt\"\nContent-Type: text/plain\r\n\r\nsome file content";
        $rawBody .= "\r\n--{$boundary}--";

        $request = $this->createRequest(['content-type' => [$contentType]], $rawBody, 'POST');
        $request->files->set('file', new UploadedFile(__FILE__, 'test.txt'));

        $handledRequest = $parser->handle($request, $this->createDummyRequestHandler(), true);

        $uploadedFiles = $handledRequest->files->all();
        $this->assertNotEmpty($uploadedFiles['someFile']);
        $this->assertFalse(isset($uploadedFiles['existingFile']));

        $bodyParams = $handledRequest->request->all();
        $expectedBodyParams = [
            'title' => 'test-title',
        ];
        $this->assertEquals($expectedBodyParams, $bodyParams);
    }
}
