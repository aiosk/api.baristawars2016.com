<?php
// Routes

$app->get('/helper/time', function ($request, $response, $args) {
    $this->logger->info("get current time and timezone");

    $tz = $this->get('settings')['time']['timezone'];
    $format = $this->get('settings')['time']['format'];
    date_default_timezone_set($tz);

    $now = new DateTime("now");
    $registration = [
        'start' => new DateTime('2016-09-20 00:00:00'),
        'end' => new DateTime('2016-09-30 23:59:59'),
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
//        ->withHeader('Access-Control-Allow-Origin', '*')
//        ->withHeader('Access-Control-Allow-Headers', 'X-Requested-With, Content-Type, Accept, Origin, Authorization')
//        ->withHeader('Access-Control-Allow-Methods', 'GET, POST, PUT, DELETE, OPTIONS');
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

//        foreach (['idcard', 'name', 'email'] as $v) {
//            $text = ucfirst($v);
//            if ($v == 'idcard') {
//                $text = 'ID card';
//            }
//            $data[$v] = filter_var(trim($data[$v]), FILTER_SANITIZE_STRING);
//            if (empty($data[$v])) {
//                $exceptionElement = $v;
//                throw new Exception(ucfirst($text) . " is empty");
//            }
//        }

        $data['address'] = filter_var(trim($data['address']), FILTER_SANITIZE_STRING);

        $formElement = 'idcard';
        formIsEmpty($data, $formElement);
        formIsValidRegexp($data, $formElement, '^[0-9]{16}$');
//        formIsValidlength($data, $formElement, 16);

        $formElement = 'name';
        formIsEmpty($data, $formElement);
        formIsValidRegexp($data, $formElement, '^[a-zA-Z ]+$');

        $formElement = 'email';
        formIsEmpty($data, $formElement);
        formIsValidEmail($data, $formElement);

        $formElement = 'dob';
        formIsEmpty($data, $formElement);
        formIsValidRegexp($data, $formElement, '\d{4}\-\d{2}\-\d{2}');

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
        $formElement = '';
        $coffeeshop_id = 0;
        if (!empty($data['coffeeshop_location'])) {
            $location = json_decode($data['coffeeshop_location']);

            $st = $this->db->prepare("SELECT id FROM coffeeshop WHERE name=:name");

            $st->execute([':name' => $location->name]);
            $coffeeshop_exist = $st->fetch();

            if (empty($coffeeshop_exist)) {
                $st = $this->db->prepare("INSERT INTO coffeeshop (name,location) VALUES (:name, :location)");
                $st->execute([
                    ':name' => $location->name,
                    ':location' => json_encode([
                        'lat' => $location->lat,
                        'lng' => $location->lng,
                        'vicinity' => $location->vicinity,
                    ]),
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

        $q = "INSERT INTO user_detail (user_id,name,dob,address,picture) VALUES (:id,:name,:dob,:address,:picture)";
        $st = $this->db->prepare($q);
        $st->execute([
            ':id' => $this->db->lastInsertId(),
            ':name' => $data['name'],
            ':dob' => $data['dob'],
            ':address' => $data['address'],
            ':picture' => $fileLoc,
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
                ':body' => 'body',
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