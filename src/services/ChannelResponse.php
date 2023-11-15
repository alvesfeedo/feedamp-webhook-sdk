<?php

namespace FeedonomicsWebHookSDK\services;

use GuzzleHttp\Exception\RequestException;
use GuzzleHttp\Psr7\Response;

class ChannelResponse
{
    /**
     * @param Response $response
     * @return array
     */
    static public function generate_successful_response(Response $response)
    {
        return [
            'headers' => $response->getHeaders(),
            'response_code' => $response->getStatusCode(),
            'response_body' => $response->getBody()->getContents(),
        ];
    }

    /**
     * @param RequestException $exception
     * @return array
     */
    public function generate_error_response(RequestException $exception)
    {
        if ($exception->hasResponse()) {
            return [
                'headers' => $exception->getResponse()->getHeaders(),
                'response_code' => $exception->getResponse()->getStatusCode(),
                'response_body' => $exception->getResponse()->getBody()->getContents(),
                'exception_message' => 'Client error response [url] ' . $exception->getRequest()->getUri() .
                    ' [status code] ' . $exception->getResponse()->getStatusCode() .
                    ' [reason phrase] ' . $exception->getResponse()->getReasonPhrase()
            ];

        }
        $error_context = $exception->getHandlerContext();
        return [
            'curl_error_code' => $error_context['errno'],
            'exception_message' => $exception->getMessage()
        ];
    }
}