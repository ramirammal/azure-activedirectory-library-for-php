<?php
/**
 * Copyright (c) 2016 Micorosft Corporation
 *
 * Permission is hereby granted, free of charge, to any person obtaining a copy
 * of this software and associated documentation files (the "Software"), to deal
 * in the Software without restriction, including without limitation the rights
 * to use, copy, modify, merge, publish, distribute, sublicense, and/or sell
 * copies of the Software, and to permit persons to whom the Software is
 * furnished to do so, subject to the following conditions:
 *
 * The above copyright notice and this permission notice shall be included in
 * all copies or substantial portions of the Software.
 *
 * THE SOFTWARE IS PROVIDED "AS IS", WITHOUT WARRANTY OF ANY KIND, EXPRESS OR
 * IMPLIED, INCLUDING BUT NOT LIMITED TO THE WARRANTIES OF MERCHANTABILITY,
 * FITNESS FOR A PARTICULAR PURPOSE AND NONINFRINGEMENT. IN NO EVENT SHALL THE
 * AUTHORS OR COPYRIGHT HOLDERS BE LIABLE FOR ANY CLAIM, DAMAGES OR OTHER
 * LIABILITY, WHETHER IN AN ACTION OF CONTRACT, TORT OR OTHERWISE, ARISING FROM,
 * OUT OF OR IN CONNECTION WITH THE SOFTWARE OR THE USE OR OTHER DEALINGS IN
 * THE SOFTWARE.
 *
 * @author Aashay Zajriya <aashay@introp.net>
 * @license MIT
 * @copyright (C) 2016 onwards Microsoft Corporation (http://microsoft.com/)
 */
session_start();
require(__DIR__ . '/../../vendor/autoload.php');

// Construct.
$httpclient = new \microsoft\aadphp\HttpClient;
$storage = new \microsoft\aadphp\OIDC\StorageProviders\SQLite(__DIR__ . '/storagedb.sqlite');
$client = new \microsoft\aadphp\AAD\Client($httpclient, $storage);

// Set credentials.
require(__DIR__ . '/config.php');
if (!defined('AADSPHP_CLIENTID') || empty(AADSPHP_CLIENTID)) {
    throw new \Exception('No client ID set - please set in config.php');
}
$client->set_clientid(AADSPHP_CLIENTID);

if (!defined('AADSPHP_CLIENTSECRET') || empty(AADSPHP_CLIENTSECRET)) {
    throw new \Exception('No client secret set - please set in config.php');
}
$client->set_clientsecret(AADSPHP_CLIENTSECRET);

if (!defined('AADSPHP_CLIENTREDIRECTURI') || empty(AADSPHP_CLIENTREDIRECTURI)) {
    throw new \Exception('No redirect URI set - please set in config.php');
}
$client->set_redirecturi(AADSPHP_CLIENTREDIRECTURI);

// Make request.
try {
    $returned = $client->rocredsrequest($_POST['username'], $_POST['password']);
} catch (Exception $e) {
    $_SESSION['error'] = true;
    header('Location: ./signin.php');
}

// Process id token.
$idtoken = \microsoft\aadphp\AAD\IDToken::instance_from_encoded($returned['id_token']);

$db = \microsoft\aadphp\samples\sqlite::get_db(__DIR__ . '/storagedb.sqlite');

if (isset($_SESSION['user_id'])) {
    $user = $db->get_user($_SESSION['user_id']);

    if ($user['email'] != strtolower($idtoken->claim('upn'))) {
        header('Location: ./user.php?no_account=1');
        die();
    }
}

$user = $db->is_user_exist($idtoken->claim('upn'));

if ($user) {
    $adUser = $db->get_ad_user($user['id']);
    if ($adUser) {
        // Update access token in db, each time user logs in.
        $db->update_ad_user($returned,$user['id']);
    } else {
        // User account present in local database but not linked yet
        if (isset($_SESSION['user_id'])) {
            // User locally signed in, directly link local account with active directory account.
            $db->insert_ad_user($returned['id_token'], $user['id'], $idtoken->claim('upn'), 'id_token');
        } else {
            // User account present in local database, ask user whether to link with active directory account.
            $data = array(
                'userid' => $user['id'],
                'emailid' => $idtoken->claim('upn'),
                'addata' => $returned['id_token'],
                'tokentype' => 'id_token'
            );
            $_SESSION['data'] = json_encode($data);
            header('Location: ./link.php');
            die();
        }
    }
} else {
    $data = array(
        'userid' => $user['id'],
        'emailid' => $idtoken->claim('upn'),
        'addata' => $returned['id_token'],
        'tokentype' => 'id_token'
    );
    $_SESSION['data'] = json_encode($data);
    header('Location: ./index.php?firstname=' . $idtoken->claim('given_name') . '&lastname=' . $idtoken->claim('family_name') . '&email=' . $idtoken->claim('upn') . '&new_acc=1');
    die();
}

$_SESSION['user_id'] = $user['id'];
header('Location: ./user.php');
?>