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
    $channel_order_ids = explode(',', $ids);

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

    if (!$channel_order_ids) {
        $validation_errors[] = [
            [
                "code" => "MISSING_QUERY_PARAM",
                "message" => "Missing required channel_order_ids"
            ]
        ];
    }
    if (count($channel_order_ids) > 250) {
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

    $status_response = $shopify_client->get_order_statuses($channel_order_ids);
    $raw_response = $status_response['response'];

    $failed_request = $shopify_client->is_error_response($raw_response);
    $orders = json_decode($raw_response['response_body'] ?? '', true);

    if ($failed_request || !$orders) {
        $response = $response->withStatus(502);
        $response->getBody()->write(json_encode([
            "failed_ids" => $channel_order_ids,
            "channel_response" => $raw_response
        ]));
        return $response;
    }


    $order_statuses = $shopify_client->parse_order_statuses_response($orders);
    $response = $response->withStatus(200);
    $return_response = [
        "statuses" => $order_statuses,
        "failed_ids" => $status_response['failed_ids'],
        "channel_response" => $raw_response
    ];

    $response->getBody()->write(json_encode($return_response));
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
    foreach (["start_date", "end_date"] as $date) {
        if (!isset($query_params[$date])) {
            $validation_errors[] = [
                "code" => "MISSING_REQUIRED_FIELD",
                "message" => "Missing value for " . $date
            ];
            continue;
        }
        if (!strtotime($query_params[$date])) {
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

    $refunds = $shopify_client->get_refunds($start_date, $end_date);

    $failed_request = isset($refunds['channel_response']);

    $order_count = $refunds['order_count'];

    if ($failed_request) {
        $response = $response->withStatus(502);
        $response->getBody()->write(json_encode([
            "error" => $refunds['error'],
            "channel_response" => $refunds['channel_response']
        ]));
        return $response;
    }

    $response = $response->withStatus(200);
    $response->getBody()->write(json_encode([
        "refunds" => $refunds["refunds"],
    ]));
    return $response;

});

$app->get('/inventory_info', function (Request $request, Response $response, $args) {
    $response = $response->withHeader('content-type', 'application/json');
    $store_id = $request->getHeaderLine('store-id');
    $token = $request->getHeaderLine('token');

    //exchange the JWT token for the access token for the identified store_id
    $access_token = $token;
    //

    $validation_errors = [];
    $query_params = $request->getQueryParams();
    $ids = $query_params['variant_ids'] ?? "";
    $variant_ids = explode(',', $ids);

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

    if (!$variant_ids) {
        $validation_errors[] = [
            [
                "code" => "MISSING_QUERY_PARAM",
                "message" => "Missing required variant_ids"
            ]
        ];
    }
    if (count($variant_ids) > 250) {
        $validation_errors[] = [
            [
                "code" => "INVALID_QUERY_PARAM",
                "message" => "Number of variant_id in variant_ids > 250"
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

    $inventory = $shopify_client->get_inventory_info($variant_ids);

    $failed_request = isset($inventory['channel_response']);
    if ($failed_request) {
        $response = $response->withStatus(502);
        $response->getBody()->write(json_encode([
            "failed_ids" => $variant_ids,
            "error" => $inventory['error'],
            "channel_response" => $inventory['channel_response']
        ]));
        return $response;
    }

    $response = $response->withStatus(200);
    $response->getBody()->write(json_encode([
        "inventory" => $inventory['inventory'],
    ]));
    return $response;

});

$app->get('/orders', function (Request $request, Response $response, $args) {
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

    if (!isset($query_params['start_date'])) {
        $validation_errors[] = [
            "code" => "MISSING_REQUIRED_FIELD",
            "message" => "Missing value for start_date"
        ];
    }
    if (!strtotime($query_params['start_date'])) {
        $validation_errors[] = [
            "code" => "FIELD_INVALID_VALUE",
            "message" => "Value for start_date could not be parsed"
        ];
    }

    if ($validation_errors) {
        $response = $response->withStatus(400);
        $response->getBody()->write(json_encode($validation_errors));
        return $response;
    }

    $start_date = $query_params['start_date'];

    $client = new HttpClient();
    $shopify_client = new ShopifyClient($store_id, $access_token, $client);

    $raw_response = $shopify_client->get_orders($start_date);

    if ($shopify_client->is_error_response($raw_response)) {
        $response = $response->withStatus(502);
    } else {
        $response = $response->withStatus(200);
    }
    $response->getBody()->write(json_encode($raw_response));
    return $response;
});
$app->run();
