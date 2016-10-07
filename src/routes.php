<?php
// Routes

$app->get('/helper/time', function ($request, $response, $args) {
    $this->logger->info("get current time and timezone");

    $tz = $this->get('settings')['time']['timezone'];
    $format = $this->get('settings')['time']['format'];
    date_default_timezone_set($tz);

    $now = new DateTime("now");
    $registration = [
        'start' => new DateTime('2016-10-01 00:00:00'),
        'end' => new DateTime('2016-10-07 23:59:59'),
    ];


    $data = [
        'current' => $now->format($format),
        'registration' => [
            'start' => $registration['start']->format($format),
            'end' => $registration['end']->format($format)
        ],
        'registrationOpen' => $now > $registration['start'] && $now < $registration['end'],
        'timezone' => $tz
    ];

    $response->withJson($data);

    return $response;
});


require __DIR__ . '/libs.php';
$app->post('/registration', function ($request, $response) {
    $this->logger->info("save registration form");

    $formElement = '';
    $response_data = [
        'status' => true,
        'message' => 'Registration success',
        'element' => $formElement,
    ];

    try {
        $data = $request->getParsedBody();

        $data['address'] = filter_var(trim($data['address']), FILTER_SANITIZE_STRING);

        $formElement = 'idcard';
        formIsEmpty($data, $formElement);
        formIsValidRegexp($data, $formElement, '^[0-9]{12,}$');

        $formElement = 'name';
        formIsEmpty($data, $formElement);
        formIsValidRegexp($data, $formElement, '^[a-zA-Z ]+$');

        $formElement = 'gender';
        formIsEmpty($data, $formElement);
        formIsValidRegexp($data, $formElement, '(?:male|female)');

        $formElement = 'email';
        formIsEmpty($data, $formElement);
        formIsValidEmail($data, $formElement);

        $formElement = '';
        $st = $this->db->prepare("SELECT count(id) total FROM user WHERE email=:email and idcard=:idcard");
        $st->execute([
            ':email' => $data['email'],
            ':idcard' => $data['idcard']
        ]);
//        throw new Exception("disini");
        $user_exist = $st->fetch();

        if ((int)$user_exist['total'] > 0) {
            throw new Exception($data['email'] . " and ID card already registered");
        }

        $formElement = 'dob';
        formIsEmpty($data, $formElement);
        formIsValidRegexp($data, $formElement, '\d{4}\-\d{2}\-\d{2}');

        $files = $request->getUploadedFiles();
        $formElement = 'picture';
        $picture = $files['picture'];
        $fileLoc = '';
        if (!empty($picture->getClientMediaType())) {
            $pictureSize = $picture->getSize() / 1024 / 1024;
            if ($pictureSize > 1) {
                throw new Exception("Picture is too big");
            }
            $fileExt = explode('/', $picture->getClientMediaType())[1];
            $fileLoc = 'uploads/' . uniqid('img_', true) . '.' . $fileExt;
            $picture->moveTo(__DIR__ . '/../../' . $fileLoc);
        } else {
            throw new Exception("Picture is empty");
        }

        $formElement = 'position';
        formIsEmpty($data, $formElement);
        formIsValidRegexp($data, $formElement, '(?:owner|barista|independent)');

        $formElement = '';
        $coffeeshop_id = 0;
        if (!empty($data['coffeeshop_location'])) {
            $location = json_decode($data['coffeeshop_location']);

            $st = $this->db->prepare("SELECT id FROM coffeeshop WHERE name=:name and place_id=:place_id");

            $st->execute([
                ':name' => $location->name,
                ':place_id' => $location->place_id
            ]);
            $coffeeshop_exist = $st->fetch();

            if (empty($coffeeshop_exist)) {
                $st = $this->db->prepare("INSERT INTO coffeeshop (name,location,geometry,vicinity,place_id) VALUES (:name, :location,:geometry,:vicinity,:place_id)");
                $st->execute([
                    ':name' => $location->name,
                    ':location' => $location->location,
                    ':geometry' => $location->geometry,
                    ':vicinity' => $location->vicinity,
                    ':place_id' => $location->place_id,
                ]);

                $coffeeshop_id = $this->db->lastInsertId();
            } else {
                $coffeeshop_id = $coffeeshop_exist['id'];
            }
        }

        $tz = $this->get('settings')['time']['timezone'];
        $format = $this->get('settings')['time']['format'];
        date_default_timezone_set($tz);
        $now = new DateTime("now");

        $st = $this->db->prepare("INSERT INTO user (email,idcard,registration_time,coffeeshop_id) VALUES (:email,:idcard,:regtime,:coffeeshop_id)");
        $st->execute([
            ':email' => $data['email'],
            ':idcard' => $data['idcard'],
            ':coffeeshop_id' => $coffeeshop_id,
            ':regtime' => $now->format($format),
        ]);

        $q = "INSERT INTO user_detail (user_id,name,dob,address,picture,gender,position) VALUES (:id,:name,:dob,:address,:picture,:gender,:position)";
        $st = $this->db->prepare($q);
        $st->execute([
            ':id' => $this->db->lastInsertId(),
            ':name' => $data['name'],
            ':dob' => $data['dob'],
            ':address' => $data['address'],
            ':picture' => $fileLoc,
            ':gender' => $data['gender'],
            ':position' => $data['position'],
        ]);

    } catch (Exception $e) {
        $response_data = [
            'status' => false,
            'message' => $e->getMessage(),
            'element' => $formElement,
//            'file' => $e->getFile(),
//            'line' => $e->getLine(),
//            'trace' => $e->getTraceAsString(),
        ];
    }

    $response->withJson($response_data);

    return $response;
});

$app->get('/cron/email/registration', function ($request, $response) {

    $response_data = [
        'status' => true,
        'message' => '',
    ];

    try {
        $st = $this->db->prepare("SELECT u.id, email, idcard, registration_time, ud.name,
job, is_sent
FROM `user` u 
LEFT JOIN user_detail ud ON u.id = ud.user_id  
LEFT JOIN user_email ue ON u.id = ue.user_id
WHERE is_sent IS NULL
ORDER BY registration_time DESC
LIMIT 1");
        $st->execute();
        $participants = $st->fetchAll();
        $response_data['items'] = $participants;

        foreach ($participants as $v) {
            $to = [$v['email'] => $v['name']];
            $regtime = new DateTime($v['registration_time']);
            $v['registration_time'] = $regtime->format('j M Y, H:i');

            $body = file_get_contents(__DIR__ . '/mail.txt');
            $body = mailRender($v, $body);

            $part = file_get_contents(__DIR__ . '/../../mail.html');
            $part = mailRender($v, $part);

            // Create the message
            $transport = Swift_MailTransport::newInstance();
            $mailer = Swift_Mailer::newInstance($transport);
            $message = Swift_Message::newInstance()
                // Give the message a subject
                ->setSubject('Registration Info')
                // Set the From address with an associative array
                ->setFrom(array('info@baristawars2016.com' => 'Barista Wars 2016'))
                // Set the To addresses with an associative array
                ->setTo($to)
                // Give it a body
                ->setBody($body)
                // And optionally an alternative body
                ->addPart($part, 'text/html');
            // Optionally add any attachments
//            ->attach(Swift_Attachment::fromPath('my-document.pdf'));
            $result = $mailer->send($message);

            $st = $this->db->prepare("INSERT INTO user_email (user_id,job,body,is_sent) VALUES (:id,:job,:body,:sent)");
            $st->execute([
                ':id' => $v['id'],
                ':job' => 'registration',
                ':body' => $body,
                ':sent' => $result,
            ]);

        }

    } catch (Exception $e) {
        $response_data = [
            'status' => false,
            'message' => $e->getMessage(),
            'file' => $e->getFile(),
            'line' => $e->getLine(),
//            'trace' => $e->getTraceAsString(),
        ];
    }
    $response->withJson($response_data);

    return $response;
});

$app->get('/participant', function ($request, $response) {
    $this->logger->info("save registration form");

    $response_data = [
        'status' => true,
        'message' => '',
    ];
    try {
        $st = $this->db->prepare("SELECT u.id,email,idcard,registration_time,confirm_time,participant_id, 
ud.name,dob,address,picture, c.name coffeeshop_name,c.location coffeeshop_location 
FROM `user` u LEFT JOIN user_detail ud ON u.id = ud.user_id 
LEFT JOIN coffeeshop c ON u.coffeeshop_id = c.id 
ORDER BY registration_time DESC");
        $st->execute();
        $participants = $st->fetchAll();
        $response_data['items'] = $participants;
        $response_data['total'] = count($participants);
    } catch (Exception $e) {
        $response_data = [
            'status' => false,
            'message' => $e->getMessage(),
            'file' => $e->getFile(),
            'line' => $e->getLine(),
//            'trace' => $e->getTraceAsString(),
        ];
    }
    $response->withJson($response_data);

    return $response;
});

use Firebase\JWT\JWT;
use Tuupola\Base62;

$app->group('/dashboard', function () {
    $this->post("/token", function ($request, $response) {
        /* Here generate and return JWT to the client. */
        $data = $request->getParsedBody();
        $response_data = [];

        try {
            $formElement = 'name';
            formIsEmpty($data, $formElement);
            $formElement = 'password';
            formIsEmpty($data, $formElement);

            $jti = Base62::encode(openssl_random_pseudo_bytes(16));
            $now = new DateTime();
            $future = new DateTime("now +2 hours");
            $payload = [
                "nbf" => $now->getTimeStamp(),
                "iat" => $now->getTimeStamp(),
                "exp" => $future->getTimeStamp(),
                "jti" => $jti,
                "iss" => 'baristawars2016.com',
                "sub" => 'dashboard',
                "aud" => $data["username"] . '@dashboard.baristawars2016.com',
            ];
            $secret = $this->get('settings')['jwt']['secret'];
            $token = JWT::encode($payload, $secret, "HS256");

            $response_data = [
                'status' => true,
                'token' => $token,
                'profile' => [
                    'username' => $param['username']
                ]
            ];
        } catch (Exception $e) {
            $response_data = [
                'status' => false,
                'message' => $e->getMessage(),
            ];
        }


        $response->withJson($response_data);
        return $response;
    });
    $this->post("/chart/gender/percentage", function ($request, $response) {
//
//        $param = $request->getParsedBody();
//
//        $token = JWT::decode($param['token'], $this->get('settings')['jwt']['secret'], ["HS256"]);
//        console.log($token);
        $response_data = [
            'male' => 30,
            'female' => 70,
        ];
        $response->withJson($response_data);
        return $response;
    });
});

