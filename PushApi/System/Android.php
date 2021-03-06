<?php

namespace PushApi\System;

use \Slim\Log;
use \PushApi\PushApi;
use \PushApi\PushApiException;
use \PushApi\Models\Device;

/**
 * @author Eloi Ballarà Madrid <eloi@tviso.com>
 * @copyright 2015 Eloi Ballarà Madrid <eloi@tviso.com>
 * @license http://www.opensource.org/licenses/mit-license.php The MIT License
 * Documentation @link https://push-api.readme.io/
 *
 * Manages the main functionalities that handles android notifications sending.
 *
 * Google Note: If your organization has a firewall that restricts the traffic to or from the Internet,
 * you need to configure it to allow connectivity with GCM in order for your Android devices to
 * receive messages. The ports to open are: 5228, 5229, and 5230. GCM typically only uses 5228,
 * but it sometimes uses 5229 and 5230. GCM does not provide specific IPs, so you should allow your
 * firewall to accept outgoing connections to all IP addresses contained in the IP blocks listed in
 * Google's ASN of 15169.
 */
class Android implements INotification
{
    const JSON = 'application/json';

    /**
     * Android response keys and descriptions
     */
    // success, no actions required
    const MESSAGE_ID = 'message_id';
    // error, the target id has a kind of error
    const ERROR = 'error';
    // notification should be resent
    const UNAVAILABLE = 'Unavailable';
    // had an unrecoverable error (maybe the value got corrupted in the database)
    const INVALID_REGISTRATION = 'InvalidRegistration';
    // the registration ID should be updated in the server database
    const REGISTRATION_ID = 'registration_id';
    // registration ID should be removed from the server database because the application was uninstalled from the device
    const NOT_REGISTERED = 'NotRegistered';

    // The GCM server url where the message will be send
    private $url = GCM_URL;
    // See documentation in order to get the $apiKey
    private $apiKey = ANDROID_KEY;
    private $authorization = "Authorization: key=";
    private $contentType = "Content-type: ";
    private $headers = array();

    private $title = PUSH_TITLE;
    private $message;

    public function setMessage($to, $subject, $theme, $message, $from = false)
    {
        if (isset($subject)) {
            $this->title = $subject;
        }

        $this->message = array(
            "registration_ids" => $to,
            "collapse_key" => $theme,
            "delay_while_idle" => true,
            // "time_to_live" => 2419200, // Default time in seconds = 4 weeks
            "data" => array(
                "title" => $this->title,
                "message" => $message,
            )
        );

        if (DEBUG) {
            // This parameter allows developers to test a request without send a real message
            $this->message["dry_run"] = DEBUG;
        }

        return isset($this->message);
    }

    public function getMessage()
    {
        if (isset($this->message)) {
            return $this->message;
        }
        return false;
    }

    /**
     * Redirect is used with non-native apps that are using the smartphone browser in order to open
     * the app. The redirect value contains the URL where the user will be taken when the notification
     * is received.
     * @param string $redirect The url where the user must be taken
     */
    public function addRedirect($redirect)
    {
        if (!isset($redirect) || empty($redirect)) {
            PushApi::log(__METHOD__ . " - Error: " . PushApiException::NO_DATA, Log::DEBUG);
            throw new PushApiException(PushApiException::NO_DATA, " Redirect is not set");
        }

        if (!isset($this->message)) {
            PushApi::log(__METHOD__ . " - Error: " . PushApiException::NO_DATA, Log::DEBUG);
            throw new PushApiException(PushApiException::NO_DATA, " Message must be created before adding redirect");
        }

        $this->message["data"]["url"] = $redirect;
        return true;
    }

    public function send()
    {
        if (!isset($this->message)) {
            PushApi::log(__METHOD__ . " - Error: " . PushApiException::NO_DATA, Log::DEBUG);
            throw new PushApiException(PushApiException::NO_DATA, "Can't send without push message created");
        }

        // Preparing HTTP headers
        $this->headers = array(
            $this->authorization . $this->apiKey,
            $this->contentType . self::JSON
        );

        // Preparing HTTP connection
        $ch = curl_init();

        // Setting the url, number of POST vars, POST data
        curl_setopt($ch, CURLOPT_URL, $this->url);

        curl_setopt($ch, CURLOPT_POST, true);
        curl_setopt($ch, CURLOPT_HTTPHEADER, $this->headers);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($this->message));

        // Disabling SSL Certificate support temporally
        curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);

        // Send POST request to Google Cloud Message Server
        $result = curl_exec($ch);

        // Fetching results or failing if doesn't work
        if ($result === false) {
            PushApi::log(__METHOD__ . " - Android Curl connection failed", Log::ERROR);
            throw new PushApiException(PushApiException::CONNECTION_FAILED, "Android Curl connection failed" . curl_error($ch));
        }

        // Closing the HTTP connection
        curl_close($ch);

        return $result;
    }

    /**
     * Checks the failures of the results and does the right action foreach case:
     * - user has uninstalled the app or has not that id -> delete the android reference
     * - user is unreachable -> resend the notification
     * - user id has changed -> update user id with the new one
     */
    public function checkResults($users, $result)
    {
        for ($i = 0; $i < sizeof($users); $i++) {
            // User can't be reached and the message should be sent again
            if (isset($result[$i]->error) && $result[$i]->error == self::UNAVAILABLE) {
                $this->message["registration_ids"] = array($users[$i]);
                $this->send();
            }

            // User id has changed or is invalid and it should be removed in order to avoid send a message again.
            if (isset($result[$i]->error) && ($result[$i]->error == self::INVALID_REGISTRATION
                    || $result[$i]->error == self::NOT_REGISTERED)) {
                $device = Device::getFullDeviceInfoByReference($users[$i]);
                if ($device) {
                    Device::removeDeviceById($device['user_id'], $device['id']);
                }
            }

            // User id has changed and it must be updated because this is the only warning that will send the GCM.
            if (isset($result[$i]->registration_id)) {
                $device = Device::getFullDeviceInfoByReference($users[$i]);
                if ($device) {
                    Device::removeDeviceById($device['user_id'], $device['id']);
                    Device::addDevice($device['user_id'], Device::TYPE_ANDROID, $result[$i]->registration_id);
                }
            }
        }
    }
}