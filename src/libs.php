<?php
function getElementExceptionName($element)
{

    switch ($element) {
        case 'idcard':
            $text = 'ID card';
            break;
        case 'dob':
            $text = 'Date of Birth';
            break;
        default:
            $text = ucfirst($element);
    }
    return $text;
}

function formIsEmpty($data, $element)
{
    $text = getElementExceptionName($element);
    $data[$element] = filter_var(trim($data[$element]), FILTER_SANITIZE_STRING);
    if (empty($data[$element])) {
        throw new Exception(ucfirst($text) . " is empty");
    }
}

function formIsValidRegexp($data, $element, $regexp)
{
    $text = getElementExceptionName($element);
    $data[$element] = filter_var(trim($data[$element]), FILTER_VALIDATE_REGEXP, array("options" => array("regexp" => "/" . $regexp . "/")));
    if (!$data[$element]) {
        throw new Exception($text . " is not valid");
    }
}

function formIsValidLength($data, $element, $length)
{
    $text = getElementExceptionName($element);
    $data[$element] = filter_var(trim($data[$element]), FILTER_SANITIZE_STRING);
    if (strlen($data[$element]) < $length) {
        throw new Exception($text . " is " . $length . " character");
    }
}

function formIsValidEmail($data, $element)
{
    $text = getElementExceptionName($element);
    $data[$element] = filter_var(trim($data[$element]), FILTER_VALIDATE_EMAIL);
    if (!$data[$element]) {
        throw new Exception($text . " is not valid");
    }
}

function mailRender($data, $text)
{
    $text = str_replace('{{name}}', $data['name'], $text);
    $text = str_replace('{{idcard}}', $data['idcard'], $text);
    $text = str_replace('{{datetime}}', $data['registration_time'], $text);
    return $text;
}