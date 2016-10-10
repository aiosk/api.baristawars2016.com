<?php
// Application middleware

// e.g: $app->add(new \Slim\Csrf\Guard);

$app->add(new \Tuupola\Middleware\Cors([
    "origin" => ["*"],
    "methods" => ["GET", "POST", "PUT", "PATCH", "DELETE","OPTIONS"],

    "error" => function ($request, $response, $arguments) {
        $data["status"] = "error";
        $data["message"] = $arguments["message"];
        return $response
            ->withHeader("Content-Type", "application/json")
            ->write(json_encode($data, JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT));
    },
    "headers.allow" => ["X-Requested-With", "Content-Type", "Accept", "Origin", "Authorization"],
    "headers.expose" => [],
    "credentials" => true,
    "cache" => 0,
]));

$app->add(new \Slim\Middleware\JwtAuthentication([
    "secure" => false,
//    "path" => "/dashboard", /* or ["/api", "/admin"] */
//    "passthrough" => ["/dashboard/token", "/admin/ping"],
    "rules" => [
        new \Slim\Middleware\JwtAuthentication\RequestPathRule([
            "path" => "/dashboard",
            "passthrough" => ["/dashboard/token", "/dashboard/ping"]
        ]),
        new \Slim\Middleware\JwtAuthentication\RequestMethodRule([
            "passthrough" => ["OPTIONS"]
        ])
    ],
    "secret" => "supersecretkeyyoushouldnotcommittogithub",

    "callback" => function ($request, $response, $arguments) use ($container) {
        $container["jwt"] = $arguments["decoded"];
    },
    "error" => function ($request, $response, $arguments) {
        $data["status"] = "error";
        $data["message"] = $arguments["message"];
        return $response
            ->withHeader("Content-Type", "application/json")
            ->write(json_encode($data));
    }
]));