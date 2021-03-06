<?php
/**
 * Implements Miscallenous API calls
 *
 * PHP Version 7.3
 *
 * @category Productivity
 * @package  CranberryMail
 * @author   CranberryWare Development Team (NetTantra Technologies) <support@oss.nettantra.com>
 * @license  GNU AGPL-3.0 https://github.com/cranberryware/cranberrymail/blob/master/LICENSE
 * @link     https://github.com/cranberryware/cranberrymail
 */
namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use Symfony\Component\Process\Process;
use Illuminate\Http\Request;
use GuzzleHttp\Client;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Artisan;

/**
 * Implements all methods required for IMAP calls
 *
 * @category Controller
 * @package  Cranberrymail
 * @author   CranberryWare Development Team (NetTantra Technologies) <support@oss.nettantra.com>
 * @license  GNU AGPL-3.0 https://github.com/cranberryware/cranberrymail/blob/master/LICENSE
 * @link     https://github.com/cranberryware/cranberrymail
 */
class WizardController extends Controller
{
    /**
     * Get imap and smtp settings for an email address
     *
     * @param Request $request Laravel Request
     *
     * @return \Illuminate\Http\JsonResponse
     */
    public function index(Request $request): \Illuminate\Http\JsonResponse
    {
        $whitelist = $this->_getValidMailDomains();
        Log::info(
            "Fetched valid domains",
            ['file' => __FILE__, 'line' => __LINE__]
        );
        $email = $request->input("email");

        $mx_records = $this->_getMxRecords($email);
        Log::info(
            "Fetch mx record for email " . $email,
            ['file' => __FILE__, 'line' => __LINE__]
        );
        $mx_records_arr = explode(" ", $mx_records);

        $max = count($whitelist);

        $data = ["index" => 0, "length" => 0];

        if (isset($mx_records_arr[1])) {
            for ($i=0;$i<$max;$i++) {
                if (strpos($mx_records_arr[1], $whitelist[$i])) {
                    if (strlen($whitelist[$i]) > $data['length']) {
                        $data['index'] = $i;
                        $data['length'] = strlen($whitelist[$i]);
                    }
                }
            }
        }

        Log::info(
            "Iterate over all available mx records and find similar mx record ",
            ['file' => __FILE__, 'line' => __LINE__]
        );

        if ($data['index'] == 0) {
            Log::info(
                "Email provider not found",
                ['file' => __FILE__, 'line' => __LINE__]
            );
            return response()->json(
                [
                    "status" => 0,
                    "msg" => "Email provider not found. \
                        Please fill the values manually."
                ], 200
            );
        } else {
            $mail_server = $this->_getMailServerSettings($whitelist[$data['index']]);
            Log::info(
                "Get mail server settings",
                ['file' => __FILE__, 'line' => __LINE__]
            );

            $imap = $mail_server['emailProvider']['incomingServer'];
            $smtp = $mail_server['emailProvider']['outgoingServer'];

            Log::info(
                "Retrieved imap and smtp settings",
                ['file' => __FILE__, 'line' => __LINE__]
            );

            return response()->json(
                [
                    "imap" => [
                        "host" => $imap[0]['hostname'],
                        "port" => $imap[0]['port'],
                        "encryption" => strtolower($imap[0]['socketType'])
                    ],
                    "smtp" => [
                        "host" => isset($smtp[0]['hostname']) ?
                            $smtp[0]['hostname'] : $smtp['hostname'],
                        "port" => isset($smtp[0]['port']) ?
                            $smtp[0]['port'] : $smtp['port'],
                        "encryption" => isset($smtp[0]['socketType']) ?
                            strtolower($smtp[0]['socketType'])
                            : strtolower($smtp['socketType'])
                    ],
                    "status" => 1,
                    "msg" => "Success, email provider detected."
                ], 200
            );
        }
    }

    /**
     * Migrates the Migration script for Laravel
     *
     * @return \Illuminate\Http\JsonResponse
     */
    public function cranMigrate(): \Illuminate\Http\JsonResponse
    {
        Artisan::call('migrate');
        Artisan::call('db:seed');

        $sess_driver = env('SESSION_DRIVER', 'file');
        if ($sess_driver !== 'database') {
            $this->controlSessionDriver("database", "production");
        }

        $data=[
            "success" => true,
            "message" => "Migrations successfully completed."
        ];
        return response()->json($data, 200);
    }

    /**
     * Gets all valid mail domains from thunderbird database
     *
     * @return array of email providers
     */
    private function _getValidMailDomains(): array
    {
        $contents = file_get_contents("https://autoconfig.thunderbird.net/v1.1");
        preg_match_All("|href=[\"'](.*?)[\"']|", $contents, $response);
        Log::info(
            "Get all valid mail domains from thunderbird",
            ['file' => __FILE__, 'line' => __LINE__]
        );
        return $response[1];
    }

    /**
     * Converts XML to Array
     *
     * @param $xml xml data
     *
     * @return array
     */
    private function _convertXmlToArray($xml): array
    {
        $xml = simplexml_load_string($xml);
        $json = json_encode($xml);
        $array = json_decode($json, true);
        Log::info(
            "Converts xml to array",
            ['file' => __FILE__, 'line' => __LINE__]
        );
        return $array;
    }

    /**
     * Fetches mail domain's smtp and imap data
     *
     * @param string $mail_domain Mail Domain
     *
     * @return array of smtp and imap configuration
     */
    private function _getMailServerSettings($mail_domain): array
    {
        $client = new Client();
        $res = $client->request(
            'GET', 'https://autoconfig.thunderbird.net/v1.1/' . $mail_domain
        );
        $xml_response = $res->getBody();
        $result = $this->_convertXmlToArray($xml_response);
        Log::info(
            "Fetch mail server settings",
            ['file' => __FILE__, 'line' => __LINE__]
        );
        return $result;
    }

    /**
     * Gets the MX record for the given email
     *
     * @param string $email Email
     *
     * @return string of mx records
     */
    private function _getMxRecords($email): string
    {
        $email = explode("@", $email);
        
        $process = new Process(['dig', '+nocmd', $email[1], 'mx', '+short']);
        $process->run();

        Log::info("Process has run", ['file' => __FILE__, 'line' => __LINE__]);

        if (! $process->isSuccessful()) {
            Log::error(
                'Dns records could not be fetched',
                ['file' => __FILE__, 'line' => __LINE__]
            );
            exit;
        }

        Log::info("Process successful", ['file' => __FILE__, 'line' => __LINE__]);

        return $process->getOutput();
    }

}
