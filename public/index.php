<?php

use FeedonomicsWebHookSDK\services\ShopifyClient;
use FeedonomicsWebHookSDK\services\JsonSchemaValidator;
use GuzzleHttp\Client as HttpClient;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;
use Slim\Factory\AppFactory;

require __DIR__ . '/../vendor/autoload.php';
const JSON_SCHEMA_FILE = __DIR__ . '/../resources/json_schema.json';

$app = AppFactory::create();

/**
 * The routing middleware should be added earlier than the ErrorMiddleware
 * Otherwise exceptions thrown from it will not be handled by the middleware
 */
$app->addRoutingMiddleware();

/**
 * Add Error Middleware
 *
 * @param bool $displayErrorDetails -> Should be set to false in production
 * @param bool $logErrors -> Parameter is passed to the default ErrorHandler
 * @param bool $logErrorDetails -> Display error details in error log
 * @param LoggerInterface|null $logger -> Optional PSR-3 Logger
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
    $validation_errors = [];
    $order_data = $request->getParsedBody();

    if (!$store_id) {
        $validation_errors[] = [
            "code" => "MISSING_REQUIRED_FIELD",
            "message" => "Missing value for required header store-id"
        ];
    }
    if (!$token) {
        $validation_errors[] = [
            "code" => "MISSING_REQUIRED_FIELD",
            "message" => "Missing value for required header token"
        ];
    }

    if (!$order_data) {
        $validation_errors[] = [
            [
                "code" => "INVALID_PAYLOAD",
                "message" => "Payload could not be parsed as JSON"
            ]
        ];
    } else {
        $validator = new JsonSchemaValidator(JSON_SCHEMA_FILE);
        $validation_errors = array_merge($validation_errors, $validator->validate($order_data, "PlaceOrder"));
    }

    if ($validation_errors) {
        $response = $response->withStatus(400);
        $response->getBody()->write(json_encode($validation_errors));
        return $response;
    }

    $client = new HttpClient();
    $shopify_client = new ShopifyClient($store_id, $access_token, $client);
    $configs = $shopify_client->get_place_order_configs($order_data['config']);

    $payload = $shopify_client->generate_place_order_payload($order_data['order'], $configs);
    $raw_response = $shopify_client->place_order($payload);


    if ($shopify_client->is_error_response($raw_response)) {
        $response = $response->withStatus(502);
    } else {
        $response = $response->withStatus(200);
    }
    $response->getBody()->write(json_encode(["channel_response" => $raw_response]));
    return $response;
});

$app->get('/order_statuses', function (Request $request, Response $response, $args) {
    $response = $response->withHeader('content-type', 'application/json');
    $store_id = $request->getHeaderLine('store-id');
    $token = $request->getHeaderLine('token');

    //exchange the JWT token for the access token for the identified store_id
    $access_token = $token;
    //

    $validation_errors = [];
    $query_params = $request->getQueryParams();
    $ids = $query_params['channel_order_ids'] ?? "";
    $validate_ids = explode(',', $ids);

    if (!$store_id) {
        $validation_errors[] = [
            "code" => "MISSING_REQUIRED_FIELD",
            "message" => "Missing value for required header store-id"
        ];
    }
    if (!$token) {
        $validation_errors[] = [
            "code" => "MISSING_REQUIRED_FIELD",
            "message" => "Missing value for required header token"
        ];
    }

    if (!$validate_ids) {
        $validation_errors[] = [
            [
                "code" => "MISSING_QUERY_PARAM",
                "message" => "Missing required channel_order_ids"
            ]
        ];
    }
    if (count($validate_ids) > 250) {
        $validation_errors[] = [
            [
                "code" => "INVALID_QUERY_PARAM",
                "message" => "Number of items in channel_order_ids > 250"
            ]
        ];
    }

    if ($validation_errors) {
        $response = $response->withStatus(400);
        $response->getBody()->write(json_encode($validation_errors));
        return $response;
    }

    $client = new HttpClient();
    $shopify_client = new ShopifyClient($store_id, $access_token, $client);

    $raw_response = $shopify_client->get_order_statuses($ids);

    $failed_request = $shopify_client->is_error_response($raw_response);
    $orders = json_decode($raw_response['response_body'] ?? '', true);

    if ($failed_request || !$orders) {
        $response = $response->withStatus(502);
        $response->getBody()->write(json_encode([
            "failed_ids" => $validate_ids,
            "channel_response" => $raw_response
        ]));
        return $response;
    }

    $order_statuses = $shopify_client->parse_order_statuses_response($orders);
    $response = $response->withStatus(200);
    $response->getBody()->write(json_encode([
        "statuses" => $order_statuses,
        "channel_response" => $raw_response
    ]));
    return $response;

});

$app->get('/order_refunds', function (Request $request, Response $response, $args) {
    $response = $response->withHeader('content-type', 'application/json');
    $store_id = $request->getHeaderLine('store-id');
    $token = $request->getHeaderLine('token');

    //exchange the JWT token for the access token for the identified store_id
    $access_token = $token;
    //

    $validation_errors = [];
    $query_params = $request->getQueryParams();


    if (!$store_id) {
        $validation_errors[] = [
            "code" => "MISSING_REQUIRED_FIELD",
            "message" => "Missing value for required header store-id"
        ];
    }
    if (!$token) {
        $validation_errors[] = [
            "code" => "MISSING_REQUIRED_FIELD",
            "message" => "Missing value for required header token"
        ];
    }
    foreach(["start_date", "end_date"] as $date)
    {
        if(!isset($query_params[$date]))
        {
            $validation_errors[] = [
                "code" => "MISSING_REQUIRED_FIELD",
                "message" => "Missing value for ".$date
            ];
            continue;
        }
        if(!strtotime($query_params[$date])){
            $validation_errors[] = [
                "code" => "FIELD_INVALID_VALUE",
                "message" => "Value for {$date} could not be parsed"
            ];
        }
    }

    if ($validation_errors) {
        $response = $response->withStatus(400);
        $response->getBody()->write(json_encode($validation_errors));
        return $response;
    }

    $start_date = $query_params['start_date'] ?? "";
    $end_date = $query_params['end_date'] ?? "";

    $client = new HttpClient();
    $shopify_client = new ShopifyClient($store_id, $access_token, $client);

    $raw_response = $shopify_client->get_refunds($start_date, $end_date);

    $failed_request = $shopify_client->is_error_response($raw_response);
    $orders = json_decode($raw_response['response_body'] ?? '', true);

    if ($failed_request || !$orders) {
        $response = $response->withStatus(502);
        $response->getBody()->write(json_encode([
            "failed_ids" => $validate_ids,
            "channel_response" => $raw_response
        ]));
        return $response;
    }

    $order_statuses = $shopify_client->parse_order_statuses_response($orders);
    $response = $response->withStatus(200);
    $response->getBody()->write(json_encode([
        "statuses" => $order_statuses,
        "channel_response" => $raw_response
    ]));
    return $response;

});

$app->run();
