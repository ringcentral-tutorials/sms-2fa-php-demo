<?php
header("Content-Type: application/json", true);
require_once('_bootstrap.php');
use RingCentral\SDK\SDK;
$dotenv = new Dotenv\Dotenv(__DIR__);
$dotenv->load();

define("OK", 0);
define("FAILED", 1);
define("LOCKED", 2);
define("INVALID", 3);
define("UNKNOWN", 4);
define("MAX_FAILURE", 2);

class Seed
{
    function __construct($error, $id, $seed)
    {
        $this->error = $error;
        $this->id = $id;
        $this->seed = $seed;
    }
    public $error;
    public $id;
    public $seed;
}

class Response
{
    function __construct($error, $message)
    {
        $this->error = $error;
        $this->message = $message;
    }
    public $error;
    public $message;
}

if (isset($_REQUEST['getseed'])) {
  getSeed();
}else if (isset($_REQUEST['login'])){
  login();
}else if (isset($_REQUEST['verifypass'])) {
  verifyPasscode();
}else if (isset($_REQUEST['resendcode'])) {
  resendCode();
}else if (isset($_REQUEST['resetpwd'])) {
  resetPwd();
}else if (isset($_REQUEST['signup'])){
  signup();
}else if (isset($_REQUEST['canlogin'])){
    try {
        $db = new SQLite3('db/users.db', SQLITE3_OPEN_READWRITE);
        if (!$db) {
            echo '{"error":1}';
        } else {
            echo '{"error":0}';
        }
    }catch(Exception $exception){
        echo '{"error":1}';
    }
}

function getSeed() {
    $db = new SQLite3('db/users.db', SQLITE3_OPEN_READWRITE | SQLITE3_OPEN_CREATE);
    if (!$db) {
        databaseError();
    }
    $query = 'CREATE TABLE if not exists seeds (id INT(11) PRIMARY KEY, seed DateTime NOT NULL)';
    $db->exec($query) or databaseError();
    date_default_timezone_set('UTC');
    $seed = date("Y-m-d H:i:s", time());
    $id = generateRandomCode(5);
    $query = "INSERT INTO seeds VALUES (" . $id . ",'" . $seed . "')";
    $ok = $db->exec($query);
    if (!$ok) {
        $result = new Seed(FAILED, $id, $seed);
    } else {
        $result = new Seed(OK, $id, $seed);
    }
    $response = json_encode($result);
    $db->close();
    echo $response;
}

function login() {
    $db = new SQLite3('db/users.db', SQLITE3_OPEN_READWRITE );
    if (!$db){
        databaseError();
    }
    $inPassword = $_POST['password'];
    $query = "SELECT * FROM seeds WHERE id=" . $_POST['id'];
    $result = $db->querySingle($query, true);
    if(!$result)
        databaseError();
    $id = $result['id'];
    $seedStr = $result['seed'];

    $query = "DELETE FROM seeds WHERE id=" . $id;
    $result = $db->query($query);
    $email = $_POST['username'];
    $query = "SELECT phoneno, pwd, failure, locked, code FROM users WHERE email='" . $email . "' LIMIT 1";
    $results = $db->query($query);
    $result = $results->fetchArray();

    if (!$result)
        die('{"error":1, "message":"Account does not exist."}');
    if ($result['locked'] == 1){
        $maskPhoneNumber = $result['phoneno'];
        for ($i=0; $i<strlen($maskPhoneNumber)-4; $i++){
            $maskPhoneNumber[$i] = "X";
        }
        $message = "Your account is temporarily locked. A verification code was sent to your mobile phone number " . $maskPhoneNumber . ". Please enter the verification code to unlock your account.";
        sendSMSMessage($db, $result['phoneno'], $email, $message);
    }else{
        $hashed = hash('sha256', $result['pwd'].$seedStr, false);
        if ($inPassword == $hashed){
        $query = "UPDATE users SET failure= 0, locked= 0, code=0, codeexpiry=0 WHERE email='" . $email . "'";
            $result = $db->exec($query);
            if (!$result)
                databaseError();
            $db->close();
            createResponse(new Response(OK, "Welcome back."));
        }else{
            $failure = $result['failure'];
            $failure = $failure + 1;
            $query = "";
            if ($failure >= MAX_FAILURE){
                $query = "UPDATE users SET failure= 0, locked= 1 WHERE email='" . $email . "'";
            }else {
                $query = "UPDATE users SET failure= " . $failure . " WHERE email='" . $email . "'";
            }
            $result = $db->query($query);
            if (!$result)
                databaseError();
            $db->close();
            createResponse(new Response(FAILED, "Wrong user name and password combination. Please try again."));
        }
    }
}

function verifyPasscode(){
    $db = new SQLite3('db/users.db', SQLITE3_OPEN_READWRITE );
    if (!$db)
        databaseError();
    $inPasscode = $_POST['passcode'];
    $email = $_POST['username'];
    $query = "SELECT locked, code, codeexpiry FROM users WHERE email='" . $email . "' LIMIT 1";
    $results = $db->query($query);
    $result = $results->fetchArray();

    if (!$result)
        databaseError();
    if ($result['locked'] == 0){
        $db->close();
        createResponse(new Response(OK, "Please login."));
    }else{
        if (strlen($inPasscode) == 6){
            $timeStamp = time();
            $gap = $timeStamp - $result['codeexpiry'];
            if ($gap < 3600){
                if ($result['code'] == $inPasscode){
                    $query = "UPDATE users SET failure= 0, locked= 0, code=0, codeexpiry=0 WHERE email='" . $email . "'";
                    $ok = $db->query($query);
                    $db->close();
                    createResponse(new Response(OK, "Please login."));
                }else{
                    // verification code not matched
                    $query = "UPDATE users SET code= 0, codeexpiry= 0 WHERE email='" . $email . "'";
                    $ok = $db->query($query);
                    $db->close();
                    createResponse(new Response(INVALID, "Invalid verification code. Click resend to get a new verification code."));
                }
            }else{
                $db->close();
                createResponse(new Response(INVALID, "Verification code expired. Click resend to get a new verification code."));
            }
        }else{
            $db->close();
            createResponse(new Response(FAILED, "Invalid verification code. Click resend to get a new verification code."));
        }
    }
}

function resendCode(){
    $db = new SQLite3('db/users.db', SQLITE3_OPEN_READWRITE );
    if (!$db)
        databaseError();

    $email = $_POST['username'];

    $query = "SELECT phoneno FROM users WHERE email='" . $email . "' LIMIT 1";
    $results = $db->query($query);
    $result = $results->fetchArray();

    if (!$result)
        databaseError();

    $maskPhoneNumber = $result['phoneno'];
    for ($i=0; $i<strlen($maskPhoneNumber)-4; $i++){
        $maskPhoneNumber[$i] = "X";
    }
    $message = "A verification code was sent to your mobile phone number " . $maskPhoneNumber . ". Please enter the verification code to unlock your account.";
    sendSMSMessage($db, $result['phoneno'], $email, $message);
}

function resetPwd(){
    $db = new SQLite3('db/users.db', SQLITE3_OPEN_READWRITE );
    if (!$db)
        databaseError();

    $email = $_POST['username'];

    $query = "SELECT phoneno, code, codeexpiry FROM users WHERE email='" . $email . "' LIMIT 1";
    $results = $db->query($query);
    $result = $results->fetchArray();

    if (!$result)
        die('{"error":1, "message":"Account does not exist."}');

    if (!isset($_POST['pwd']) && !isset($_POST['code'])){
        $maskPhoneNumber = $result['phoneno'];
        for ($i=0; $i<strlen($maskPhoneNumber)-4; $i++){
            $maskPhoneNumber[$i] = "X";
        }
        $message = "A verification code was sent to your mobile phone number " . $maskPhoneNumber . ". Please enter the verification code to reset your password.";
        sendSMSMessage($db, $result['phoneno'], $email, $message);
    }else{
        $pwd = $_POST['pwd'];
        $inPasscode = $_POST['code'];
        if (strlen($inPasscode) == 6){
            $timeStamp = time();
            $gap = $timeStamp - $result['codeexpiry'];
            if ($gap < 3600){
                if ($result['code'] == $inPasscode){
                    $query = "UPDATE users SET pwd= '" . $pwd . "', code=0, locked=0, failure=0, codeexpiry=0 WHERE email='" . $email . "'";
                    $ok = $db->query($query);
                    if (!$ok)
                        databaseError();
                    $db->close();
                    createResponse(new Response(OK, "Password changed successfully."));
                }else{
                    $query = "UPDATE users SET code= 0, codeexpiry= 0 WHERE email='" . $email . "'";
                    $db->query($query);
                    $db->close();
                    createResponse(new Response(INVALID, "Invalid verification code. Click resend to get a new verification code."));
                }
            }else{
                $db->close();
                createResponse(new Response(INVALID, "Verification code expired. Click resend to get a new verification code."));
            }
        }else{
            $db->close();
            createResponse(new Response(FAILED, "Invalid verification code. Click resend to get a new verification code."));
        }
    }
}

function signup(){
    $db = new SQLite3('db/users.db', SQLITE3_OPEN_READWRITE | SQLITE3_OPEN_CREATE );
    if (!$db)
        databaseError();

    $query = 'CREATE TABLE if not exists users (id INT AI PRIMARY KEY, phoneno VARCHAR(12) UNIQUE NOT NULL, email VARCHAR(64) UNIQUE NOT NULL, pwd VARCHAR(256) NOT NULL, fname VARCHAR(48) NOT NULL, lname VARCHAR(48) NOT NULL, failure INT DEFAULT 0, locked INT DEFAULT 0, code INT(11) DEFAULT 0, codeexpiry DOUBLE DEFAULT 0)';
    $ok = $db->exec($query);
    if (!$ok)
        databaseError();

    $valStr = "NULL,'" . $_POST["phoneno"] . "',";
    $valStr .= "'" . $_POST["email"] . "',";
    $valStr .= "'" . $_POST["password"] . "',";
    $valStr .= "'" . $_POST["fname"] . "',";
    $valStr .= "'" . $_POST["lname"] . "',0,0,0,0";

    $query = "INSERT INTO users VALUES (" . $valStr . ")";
    $ok = $db->exec($query);
    $db->close();
    if (!$ok){
        $res = new Response(FAILED, "Signup failed. Please try again.");
        $response= json_encode($res);
        die ($response);
    }
    createResponse(new Response(OK, "Congratulations."));
}

function generateRandomCode($length) {
    $min = 10;
    for ($i=1; $i<$length-1; $i++) {
        $min *= 10;
    }
    $max = ($min * 10) - 1;
    return mt_rand($min, $max);
}

function sendSMSMessage($db, $phoneno, $email, $message){
    $rcsdk = null;
    if (getenv('ENVIRONMENT_MODE') == "sandbox") {
        $rcsdk = new SDK(getenv('CLIENT_ID_SB'),
            getenv('CLIENT_SECRET_SB'), RingCentral\SDK\SDK::SERVER_SANDBOX);
    }else{
        $rcsdk = new SDK(getenv('CLIENT_ID_PROD'),
            getenv('CLIENT_SECRET_PROD'), RingCentral\SDK\SDK::SERVER_PRODUCTION);
    }
    $platform = $rcsdk->platform();
    try {
        $un = "";
        $pwd = "";
        if (getenv('ENVIRONMENT_MODE') == "sandbox"){
            $username = getenv('USERNAME_SB');
            $pwd = getenv('PASSWORD_SB');
        }else{
            $username = getenv('USERNAME_PROD');
            $pwd = getenv('PASSWORD_PROD');
        }
        $platform->login($username, null, $pwd);
        $code = generateRandomCode(6);
        $myNumber = $username;
        try {
            $response = $platform->post('/account/~/extension/~/sms', array(
                'from' => array('phoneNumber' => $myNumber),
                'to' => array(array('phoneNumber' => $phoneno)),
                'text' => "Your verification code is " . $code
            ));
            $status = $response->json()->messageStatus;
            if ($status == "SendingFailed" || $status == "DeliveryFailed") {
                $db->close();
                    createResponse(new Response(FAILED, "RC server connection error. Please try again."));
            }else {
                $timeStamp = time();
                $query = "UPDATE users SET code= " . $code . ", codeexpiry= " . $timeStamp . " WHERE email='" . $email . "'";
                $db->query($query);
                $db->close();
                createResponse(new Response(LOCKED, $message));
            }
        }catch (\RingCentral\SDK\Http\ApiException $e) {
            $db->close();
            createResponse(new Response(FAILED, "RC server connection error. Please try again."));
        }
    }catch (\RingCentral\SDK\Http\ApiException $e) {
      $db->close();
      createResponse(new Response(FAILED, "RC server connection error. Please try again."));
    }
    /*
    $credentials = require('_credentials.php');
    $rcsdk = new SDK($credentials['appKey'], $credentials['appSecret'], $credentials['server'], '2FA Demo', '1.0.0');
    $platform = $rcsdk->platform();
    try {
      $platform->login($credentials['username'], $credentials['extension'], $credentials['password']);
      $code = generateRandomCode(6);
      $myNumber = $credentials['username'];
      try {
          $response = $platform->post('/account/~/extension/~/sms', array(
              'from' => array('phoneNumber' => $myNumber),
              'to' => array(array('phoneNumber' => $phoneno)),
              'text' => "Your verification code is " . $code
          ));

          $status = $response->json()->messageStatus;
          if ($status == "SendingFailed" || $status == "DeliveryFailed") {
              $db->close();
              createResponse(new Response(FAILED, "RC server connection error. Please try again."));
          }else {
              $timeStamp = time();
              $query = "UPDATE users SET code= " . $code . ", codeexpiry= " . $timeStamp . " WHERE email='" . $email . "'";
              $db->query($query);
              $db->close();
              createResponse(new Response(LOCKED, $message));
          }
      }catch (\RingCentral\SDK\Http\ApiException $e) {
          $db->close();
          createResponse(new Response(FAILED, "RC server connection error. Please try again."));
      }
    }catch (\RingCentral\SDK\Http\ApiException $e) {
        $db->close();
        createResponse(new Response(FAILED, "RC server connection error. Please try again."));
    }
    */
}

function createResponse($res){
    $response= json_encode($res);
    echo $response;
}
function databaseError(){
    $res = new Response(UNKNOWN, "Unknown database error. Please try again.");
    $response= json_encode($res);
    die ($response);
}
