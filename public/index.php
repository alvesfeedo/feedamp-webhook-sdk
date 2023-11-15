<?php

use GuzzleHttp\Exception\RequestException;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;
use Slim\Factory\AppFactory;

use GuzzleHttp\Client as HttpClient;
use FeedonomicsWebHookSDK\services\ShopifyClient;
use FeedonomicsWebHookSDK\services\JsonSchemaValidator;

require __DIR__ . '/../vendor/autoload.php';
const JSON_SCHEMA_FILE = __DIR__.'/../resources/json_schema.json';

$app = AppFactory::create();

/**
 * The routing middleware should be added earlier than the ErrorMiddleware
 * Otherwise exceptions thrown from it will not be handled by the middleware
 */
$app->addRoutingMiddleware();

/**
 * Add Error Middleware
 *
 * @param bool                  $displayErrorDetails -> Should be set to false in production
 * @param bool                  $logErrors -> Parameter is passed to the default ErrorHandler
 * @param bool                  $logErrorDetails -> Display error details in error log
 * @param LoggerInterface|null  $logger -> Optional PSR-3 Logger  
 *
 * Note: This middleware should be added last. It will not handle any exceptions/errors
 * for middleware added after it.
 */
$errorMiddleware = $app->addErrorMiddleware(true, true, true);

$app->addBodyParsingMiddleware();
/**
 * ROUTES
 */

$app->post('/place_order', function (Request $request, Response $response, $args) {
    $response = $response->withHeader('content-type', 'application/json');
    $store_id = $request->getHeaderLine('store-id');
    $token = $request->getHeaderLine('token');

    //exchange the JWT token for the access token for the identified store_id
        $access_token = $token;
    //

    $order_data = $request->getParsedBody();
    if(!$order_data) {
        $validation_errors = [
            [
                "code"=> "INVALID_PAYLOAD",
                "message"=> "Payload could not be parsed as JSON"
            ]
        ];
    } else {
        $validator = new JsonSchemaValidator(JSON_SCHEMA_FILE);
        $validation_errors = $validator->validate($order_data, "PlaceOrder");
    }

    if($validation_errors) {
        $response = $response->withStatus(400);
        $response->getBody()->write(json_encode($validation_errors));
        return $response;
    }

    $shopify_client = new ShopifyClient();
    $configs = $shopify_client->get_place_order_configs($order_data['config']);
    $client = new HttpClient();

    $order = $order_data['orders'][0];
    $payload = $shopify_client->generate_place_order_payload($order, $configs);
    $raw_response = $shopify_client->place_order($store_id, $access_token, $client, $payload);


    if($shopify_client->is_error_response($raw_response)) {
        $response = $response->withStatus(502);
    } else{
        $response = $response->withStatus(200);
    }
    $response->getBody()->write(json_encode(["channel_response" => $raw_response]));
    return $response;
});

$app->run();


