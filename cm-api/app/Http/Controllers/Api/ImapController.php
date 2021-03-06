<?php
/**
 * Implements calls to IMAP server
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
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Mail_mime;

/**
 * Implements all methods required for IMAP calls
 *
 * @category Controller
 * @package  Cranberrymail
 * @author   CranberryWare Development Team (NetTantra Technologies) <support@oss.nettantra.com>
 * @license  GNU AGPL-3.0 https://github.com/cranberryware/cranberrymail/blob/master/LICENSE
 * @link     https://github.com/cranberryware/cranberrymail
 */
class ImapController extends Controller
{
    /**
     * Formats MailBox Folder Names
     *
     * @param string $folder_name the folder name of mailbox
     *
     * @return string
     */
    private function _formatFolderName($folder_name): string
    {
        $folder_arr = preg_split('/[.\/]+/', $folder_name);
        $folder_name = count($folder_arr) > 0 ? end($folder_arr) : $folder_name;
        return strtolower($folder_name);
    }

    /**
     * Fetch folders
     *
     * @param Request $request the laravel request
     *
     * @return \Illuminate\Http\JsonResponse
     * @throws \Horde_Imap_Client_Exception
     */
    public function getFolders(Request $request): \Illuminate\Http\JsonResponse
    {
        $oClient = $this->getIMAPCredential();

        $mailBoxes = $this->_getMailBoxes($oClient);
        Log::info("Got mailboxes", ['file' => __FILE__, 'line' => __LINE__]);
        $data = [];
        foreach ($mailBoxes as $mailBox) {
            $mailbox_name = $mailBox['mailbox']->utf8;
            $mailbox_formatted = $this->_formatFolderName($mailbox_name);
            if (in_array($mailbox_formatted, ['drafts', 'draft'])) {
                $request->session()->put('draft_folder', $mailbox_name);
            }
            array_push($data, $mailbox_name);
        }
        Log::info(
            "Retrieved mailboxes as an array",
            ['file' => __FILE__, 'line' => __LINE__]
        );
        return response()->json($data, 200);
    }


    /**
     * Moves message to destination folder from source folder
     *
     * @param Horde_Imap_Client_Socket $oClient           Horde Client Object
     * @param string                   $sourceFolder      source mailbox folder
     * @param $uid               uid of email
     * @param string                   $destinationFolder destination mailbox folder
     *
     * @return Boolean
     */
    private function _moveToFolder(
        $oClient, $sourceFolder, $uid, $destinationFolder
    ): bool {
        $params = [
            "ids" => $uid,
            "move" => true
        ];
        $result = $oClient->copy($sourceFolder, $destinationFolder, $params);

        if (!empty($result)) {
            Log::info(
                "Moved email from ".$sourceFolder." to folder ".$destinationFolder,
                ['file' => __FILE__, 'line' => __LINE__]
            );
            return true;
        }
        Log::error(
            "Unable to move email from ".$sourceFolder
                ." to folder ".$destinationFolder,
            ['file' => __FILE__, 'line' => __LINE__]
        );
        return false;
    }

    /**
     * Copy message to destination folder from source folder
     *
     * @param Horde_Imap_Client_Socket $oClient           Horde Client Object
     * @param string                   $sourceFolder      source mailbox folder
     * @param $uid               uid of email
     * @param string                   $destinationFolder destination mailbox folder
     *
     * @return Boolean
     */
    private function _copyToFolder(
        $oClient, $sourceFolder, $uid, $destinationFolder
    ): bool {
        $params = [
            "create" => true,
            "ids" => $uid
        ];
        $result = $oClient->copy($sourceFolder, $destinationFolder, $params);

        if (!empty($result)) {
            $message = "Copied email from ". $sourceFolder
                . " to folder " . $destinationFolder;
            Log::info(
                "Copied email from ". $sourceFolder
                    . " to folder " . $destinationFolder,
                ['file' => __FILE__, 'line' => __LINE__]
            );
            return true;
        }
        Log::error(
            "Unable to copy email from " . $sourceFolder
                . " to folder " . $destinationFolder,
            ['file' => __FILE__, 'line' => __LINE__]
        );
        return false;
    }

    /**
     * Move emails to inbox from trash.
     *
     * @param Request $request Laravel request variable
     *
     * @return \Illuminate\Http\JsonResponse
     * @throws \Horde_Imap_Client_Exception
     */
    public function unTrashEmails(Request $request): \Illuminate\Http\JsonResponse
    {
        $oClient = $this->getIMAPCredential();

        $trash = $this->_getMailBox($oClient, $request->input("trash"));
        $inbox = $this->_getMailBox($oClient, $request->input("curfolder"));

        Log::info(
            "Fetched trash and inbox",
            ['file' => __FILE__, 'line' => __LINE__]
        );

        $uid = $request->input("uid");
        $uids = json_decode($uid, true);

        $ids = new \Horde_Imap_Client_Ids($uids);
        $result = $this->_moveToFolder($oClient, $trash, $ids, $inbox);

        $data=[
            "result" => 1,
            "status" => $result
        ];

        Log::info(
            "Data fetched in an array",
            ['file' => __FILE__, 'line' => __LINE__]
        );

        return response()->json($data, 200);

    }

    /**
     * Move emails to trash.
     *
     * @param Request $request Laravel request variable
     *
     * @return \Illuminate\Http\JsonResponse
     * @throws \Horde_Imap_Client_Exception
     * @throws \Horde_Imap_Client_Exception_NoSupportExtension
     */
    public function trashEmails(Request $request): \Illuminate\Http\JsonResponse
    {
        $oClient = $this->getIMAPCredential();

        $trashFolder = $this->_getMailBox($oClient, $request->input("trash"));
        $curFolder = $this->_getMailBox($oClient, $request->input("curfolder"));

        Log::info(
            "Fetched trash and curFolder",
            ['file' => __FILE__, 'line' => __LINE__]
        );

        $uid = $request->input("uid");
        $uids = json_decode($uid, true);

        if ($trashFolder->utf8 != $curFolder->utf8) {
            Log::info(
                "Not inside trash folder",
                ['file' => __FILE__, 'line' => __LINE__]
            );

            $ids = new \Horde_Imap_Client_Ids($uids);
            $result = $this->_moveToFolder($oClient, $curFolder, $ids, $trashFolder);
            $data=[
                "result" => 1,
                "status" => $result
            ];
        } else {
            Log::info(
                "Inside folder other than trash",
                ['file' => __FILE__, 'line' => __LINE__]
            );
            $oClient->store(
                $trashFolder,
                [
                    'ids' => new \Horde_Imap_Client_Ids($uids),
                    'add' => '\deleted',
                ]
            );

            Log::info(
                "Added deleted flag to given emails in trash folder",
                ['file' => __FILE__, 'line' => __LINE__]
            );

            $result = $oClient->expunge(
                $trashFolder,
                [
                    'ids' => new \Horde_Imap_Client_Ids($uids),
                    'list' => true
                ]
            );

            Log::info(
                "Expunged given emails in trash folders",
                ['file' => __FILE__, 'line' => __LINE__]
            );

            if (!empty($result)) {
                Log::info(
                    "List of uid fetched after expunge",
                    ['file' => __FILE__, 'line' => __LINE__]
                );
                $result=1;
            } else {
                Log::info(
                    "No uids fetched after expunge",
                    ['file' => __FILE__, 'line' => __LINE__]
                );
                $result=0;
            }

            $data=[
                "result" => $result,
                "status" => boolval($result)
            ];
        }
        Log::info(
            "@22: Returning data as an array",
            ['file' => __FILE__, 'line' => __LINE__]
        );
        return response()->json($data, 200);
    }

    /**
     * Move emails to inbox from spam.
     *
     * @param Request $request Laravel Request Variable
     *
     * @return \Illuminate\Http\JsonResponse
     * @throws \Horde_Imap_Client_Exception
     */
    public function unSpamEmails(Request $request): \Illuminate\Http\JsonResponse
    {
        $oClient = $this->getIMAPCredential();

        $spam = $this->_getMailBox($oClient, $request->input("spam"));
        $inbox = $this->_getMailBox($oClient, $request->input("curfolder"));

        Log::info(
            "Got mailboxes for spam and inbox ",
            ['file' => __FILE__, 'line' => __LINE__]
        );

        $uid = $request->input("uid");
        $uids = json_decode($uid, true);

        $ids = new \Horde_Imap_Client_Ids($uids);
        $result = $this->_moveToFolder($oClient, $spam, $ids, $inbox);

        $data=[
            "result" => 1,
            "status" => $result
        ];

        Log::info(
            "Returning the result of operation",
            ['file' => __FILE__, 'line' => __LINE__]
        );

        return response()->json($data, 200);
    }

    /**
     * Move emails to inbox from spam.
     *
     * @param Request $request Laravel Request Variable
     *
     * @return \Illuminate\Http\JsonResponse
     * @throws \Horde_Imap_Client_Exception
     */
    public function saveDraft(Request $request): \Illuminate\Http\JsonResponse
    {
        $oClient = $this->getIMAPCredential();

        $draft_folder = $request->session()->get('draft_folder', '');

        $draft_id = $request->input("draft_id");

        if ($draft_id) {
            $ids = new \Horde_Imap_Client_Ids($draft_id);

            $oClient->store(
                $draft_folder, ['ids' => $ids, 'add' => '\deleted']
            );

            Log::info(
                "Added deleted flag to previous draft copy of the email",
                ['file' => __FILE__, 'line' => __LINE__]
            );

            $result = $oClient->expunge(
                $draft_folder, ['ids' => $ids, 'list' => true]
            );
        }

        $tos = $request->input("to");
        $ccs = $request->input("cc");
        $bccs = $request->input("bcc");
        $subject = $request->input("subject");
        $body=$request->input("body");

        $mail_mime = new Mail_mime();
        $mail_mime->addTo($tos);
        $mail_mime->addCc($ccs);
        $mail_mime->addBcc($bccs);
        $mail_mime->setSubject($subject);
        $mail_mime->setFrom($this->user->email);

        $mail_mime->setHTMLBody($body);

        if ($request->hasFile('attachment')) {
            $files = $request->file('attachment');
            foreach ($files as $file) {
                $mail_mime->addAttachment(
                    $file, 'application/octet-stream', $file->getClientOriginalName()
                );
            }
        } else if ($request->input("attachmentURLs")) {
            $attached_urls = $request->input("attachmentURLs");
            $urls_arr = json_decode($attached_urls, true);
            foreach ($urls_arr as $file) {
                $file_path = storage_path('app/') . $file["file"];
                $mail_mime->addAttachment(
                    $file_path, 'application/octet-stream', $file["file"]
                );
            }
        }

        $msg = $mail_mime->getMessage();

        $append_body[] = ['data' => $msg];

        $draft = $oClient->append(
            $draft_folder,
            $append_body
        );
        $draft = $draft->ids;

        $data = [
            "success" => true,
            "draft" => $draft[0]
        ];

        Log::info(
            "Returning the result of operation",
            ['file' => __FILE__, 'line' => __LINE__]
        );

        return response()->json($data, 200);
    }

    /**
     * Mark emails as spam.
     *
     * @param Request $request Laravel Request Variable
     *
     * @return \Illuminate\Http\JsonResponse
     * @throws \Horde_Imap_Client_Exception
     */
    public function spamEmails(Request $request): \Illuminate\Http\JsonResponse
    {
        $oClient = $this->getIMAPCredential();

        $spamFolder = $this->_getMailBox($oClient, $request->input("spam"));
        $curFolder = $this->_getMailBox($oClient, $request->input("curfolder"));

        Log::info(
            "Fetched spamfolder and currentfolder",
            ['file' => __FILE__, 'line' => __LINE__]
        );

        $uid = $request->input("uid");
        $uids = json_decode($uid, true);

        if ($spamFolder->utf8 != $curFolder->utf8) {
            Log::info(
                "Current folder is not spam folder",
                ['file' => __FILE__, 'line' => __LINE__]
            );

            $ids = new \Horde_Imap_Client_Ids($uids);
            $result = $this->_moveToFolder($oClient, $curFolder, $ids, $spamFolder);
            $data=[
                "result" => 1,
                "status" => $result
            ];
        } else {
            Log::info(
                "Current folder is spam folder",
                ['file' => __FILE__, 'line' => __LINE__]
            );
            $data=[
                "result" => 0,
                "status" => false
            ];
        }
        Log::info(
            "Returning results of the operation",
            ['file' => __FILE__, 'line' => __LINE__]
        );
        return response()->json($data, 200);
    }

    /**
     * Mark or Unmark emails as starred
     *
     * @param Request $request Laravel Request
     *
     * @return \Illuminate\Http\JsonResponse
     * @throws \Horde_Imap_Client_Exception
     */
    public function starEmails(Request $request): \Illuminate\Http\JsonResponse
    {
        $oClient = $this->getIMAPCredential();

        $curFolder = $request->input("curFolder");
        $starEmail = $request->input("emailState");

        $uid = $request->input("uid");
        $curFolder = $this->_getMailBox($oClient, $request->input("curFolder"));
        $starred = $this->_getMailBox($oClient, $request->input("starredFolder"));
        $inbox = $this->_getMailBox($oClient, "inbox");

        Log::info(
            "Fetched curFolder,starred and inbox",
            ['file' => __FILE__, 'line' => __LINE__]
        );

        $uids = json_decode($uid, true);
        $result = true;
        $ids = new \Horde_Imap_Client_Ids($uids);

        if ($starred=='') {
            Log::info(
                "Starred folder returned empty",
                ['file' => __FILE__, 'line' => __LINE__]
            );
            $starred = $this->_getMailBox($oClient, "INBOX.Starred");
            Log::info(
                "Fetched starred folder again",
                ['file' => __FILE__, 'line' => __LINE__]
            );
        }

        if ($starred=='') {
            Log::info(
                "Starred folder returned empty after 2nd trial",
                ['file' => __FILE__, 'line' => __LINE__]
            );
            $data = [
                "result" => 0,
                "status" => "Unable to create starred folder"
            ];
        } elseif ($curFolder->utf8==$starred->utf8 && $starEmail == 1) {
            Log::info(
                "Inside starred folder and email already marked as star",
                ['file' => __FILE__, 'line' => __LINE__]
            );
            $data=[
                    "result" => 0,
                    "status" => "Message is already in starred folder"
            ];
        } elseif ($curFolder->utf8==$starred->utf8 && $starEmail == 0) {
            Log::info(
                "Inside starred folder and email to be unmarked as star",
                ['file' => __FILE__, 'line' => __LINE__]
            );
            $tmp = $this->_moveToFolder($oClient, $starred, $ids, $inbox);
            $result= $result && $tmp;

            $data=[
                "result" => 1,
                "status" => $result
            ];
        } else {
            if ($starEmail) {
                Log::info(
                    "Inside other folder and email to be moved to starred folder",
                    ['file' => __FILE__, 'line' => __LINE__]
                );

                $tmp = $this->_moveToFolder($oClient, $curFolder, $ids, $starred);
                $result= $result && $tmp;
            }

            $data=[
                "result" => 1,
                "status" => $result
            ];
        }

        return response()->json($data, 200);
    }

    /**
     * Search emails for a given text
     *
     * @param Request $request Laravel Request
     *
     * @return \Illuminate\Http\JsonResponse
     * @throws \Horde_Imap_Client_Exception
     * @throws \Horde_Imap_Client_Exception_NoSupportExtension
     */
    public function searchEmails(Request $request): \Illuminate\Http\JsonResponse
    {
        $mailbox = $request->input("curfolder");
        $sparam = $request->input("sterm");

        $query = new \Horde_Imap_Client_Search_Query();
        $query->intervalSearch(
            604800, // 604800 = 60 seconds * 60 minutes * 24 hours * 7 days (1 week)
            \Horde_Imap_Client_Search_Query::INTERVAL_YOUNGER
        );
        $query->text($sparam);

        Log::info(
            "Search Emails Defined query parameters",
            ['file' => __FILE__, 'line' => __LINE__]
        );

        $data = $this->_fetchMailQuery("search", $mailbox, $query);

        Log::info(
            "Mail Search Complete",
            ['file' => __FILE__, 'line' => __LINE__]
        );

        return response()->json($data, 200);
    }

    /**
     * Fetchs mail list for listing and searching
     *
     * @param string                         $type      Type of request
     * @param string                         $mailbox   Mailbox name
     * @param Horde_Imap_Client_Search_Query $searchqry Search Query Object
     *
     * @return array
     * @throws \Horde_Imap_Client_Exception
     * @throws \Horde_Imap_Client_Exception_NoSupportExtension
     */
    private function _fetchMailQuery($type, $mailbox, $searchqry): array
    {
        $oClient = $this->getIMAPCredential();

        $thread = $oClient->thread(
            $mailbox,
            [
                'criteria' => \Horde_Imap_Client::THREAD_ORDEREDSUBJECT,
                "search" => $searchqry
            ]
        );

        $allThreads = $thread->getThreads();

        Log::info(
            "Retrieved results as threads",
            ['file' => __FILE__, 'line' => __LINE__]
        );

        $emailThread = [];
        $uids = [];

        foreach ($allThreads as $uthread) {
            $curThread = array_keys($uthread);
            $threadCount = count($curThread);

            $emailThread[] = [
                "uids" => implode(",", $curThread),
                "count" => $threadCount
            ];
            $uids[] = $curThread[$threadCount-1];
        }

        Log::info(
            "Retrieved latest message of each unique thread",
            ['file' => __FILE__, 'line' => __LINE__]
        );

        $uids = new \Horde_Imap_Client_Ids($uids);

        $query = new \Horde_Imap_Client_Fetch_Query();
        $query->envelope();
        $query->structure();

        $messages = $oClient->fetch($mailbox, $query, array('ids' => $uids));

        Log::info(
            "Fetched messages with envelope and structure",
            ['file' => __FILE__, 'line' => __LINE__]
        );

        $data = [];
        $index = 0;

        foreach ($messages as $message) {
            $envelope = $message->getEnvelope();
            $part = $message->getStructure();

            $recipients = $envelope->to->bare_addresses;
            $senders    = $envelope->from->bare_addresses;
            $cc         = $envelope->cc->bare_addresses;
            $bcc        = $envelope->bcc->bare_addresses;
            $subject    = $envelope->subject;
            $timestamp  = $envelope->date->getTimestamp();

            $data[$index] = [
                'uid' => $message->getUid(),
                'from' => implode(",", $senders),
                'cc' => implode(",", $cc),
                'bcc' => implode(",", $bcc),
                'to' => implode(",", $recipients),
                'date' => $timestamp,
                'subject' => $subject,
                'hasAttachments' => $part->getDisposition(),
                'folder' => $mailbox,
                'body' => '',
                'messageId' =>  $envelope->message_id,
                'thread' => $emailThread[$index],
                'flags' => $message->getFlags()
            ];

            $index++;
        }

        Log::info(
            "Iterated over fetched messages",
            ['file' => __FILE__, 'line' => __LINE__]
        );

        return $data;
    }


    /**
     * Get IMAP mailbox folders
     *
     * @param Horde_Imap_Client_Socket $oClient Horde IMAP Client Object
     *
     * @return mixed
     */
    private function _getMailBoxes($oClient)
    {
        $mailBoxes = $oClient->listMailboxes("*");
        Log::info("Get all mailboxes", ['file' => __FILE__, 'line' => __LINE__]);
        return $mailBoxes;
    }

    /**
     * Retrieve particular mailbox
     *
     * @param Horde_Imap_Client_Socket $oClient Horde IMAP Client Object
     * @param $ref     Reference
     *
     * @return mixed|string
     */
    private function _getMailBox($oClient, $ref)
    {
        $mailBoxes = $this->_getMailBoxes($oClient);
        $flag=0;
        $ref1 = strtolower($ref);
        foreach ($mailBoxes as $mailBox) {
            $curMailBox = $mailBox['mailbox'];
            $tmp = strtolower($curMailBox->utf8);
            if (strpos($tmp, $ref1) || $tmp == $ref1) {
                $flag=1;
                break;
            }
        }
        Log::info(
            "Search for mailbox ".$ref." in existing mailboxes",
            ['file' => __FILE__, 'line' => __LINE__]
        );

        if ($flag==0) {
            try {
                $oClient->createMailbox($ref);
                Log::info(
                    "Create mailbox ".$ref,
                    ['file' => __FILE__, 'line' => __LINE__]
                );
            } catch(\Exception $e) {
                report($e);
                Log::error(
                    "Unable to create mailbox ".$ref,
                    ['file' => __FILE__, 'line' => __LINE__]
                );
                return '';
            }
            $mailBoxes = $this->_getMailBoxes($oClient);
            foreach ($mailBoxes as $mailBox) {
                $curMailBox = $mailBox['mailbox'];
                $tmp = $curMailBox->utf8;
                if (strpos($tmp, $ref) || $tmp == $ref) {
                    $flag=1;
                    break;
                }
            }
            Log::info(
                "Iterate once again on existing mailboxes and retrieve mailbox "
                    .$ref,
                ['file' => __FILE__, 'line' => __LINE__]
            );
        }

        return $curMailBox;
    }


    /**
     * Fetch emails for a folder
     *
     * @param Request $request Laravel Request
     *
     * @return \Illuminate\Http\JsonResponse
     * @throws \Horde_Imap_Client_Exception
     * @throws \Horde_Imap_Client_Exception_NoSupportExtension
     */
    public function getEmails(Request $request): \Illuminate\Http\JsonResponse
    {
        $oClient = $this->getIMAPCredential();

        $mailbox = $request->input("folder");
        $query = new \Horde_Imap_Client_Search_Query();
        $query->intervalSearch(
            604800, // 604800 = 60 seconds * 60 minutes * 24 hours * 7 days (1 week)
            \Horde_Imap_Client_Search_Query::INTERVAL_YOUNGER
        );

        Log::info(
            "List Emails Defined query parameters",
            ['file' => __FILE__, 'line' => __LINE__]
        );

        $data = $this->_fetchMailQuery("list", $mailbox, $query);

        Log::info(
            "List Emails data fetched",
            ['file' => __FILE__, 'line' => __LINE__]
        );

        return response()->json($data, 200);
    }

    /**
     * Fetch email by uid.
     *
     * @param Request $request Laravel Request
     *
     * @return \Illuminate\Http\JsonResponse
     * @throws \Horde_Imap_Client_Exception
     * @throws \Horde_Imap_Client_Exception_NoSupportExtension
     */
    public function getEmail(Request $request): \Illuminate\Http\JsonResponse
    {
        $oClient = $this->getIMAPCredential();

        $mailbox = $request->input("folder");

        $thread_uids = explode(",", $request->input('thread_uids'));
        $uids = new \Horde_Imap_Client_Ids($thread_uids);

        $query = new \Horde_Imap_Client_Fetch_Query();
        $query->envelope();
        $query->structure();

        $messages = $oClient->fetch($mailbox, $query, array('ids' => $uids));

        Log::info(
            "Fetch messages from mailbox ".$mailbox ." by uids",
            ['file' => __FILE__, 'line' => __LINE__]
        );

        $results = [];
        foreach ($messages as $message) {

            $envelope = $message->getEnvelope();
            $structure = $message->getStructure();

            $recipients = $envelope->to->bare_addresses;
            $senders    = $envelope->from->bare_addresses;
            $cc         = $envelope->cc->bare_addresses;
            $bcc         = $envelope->bcc->bare_addresses;
            $subject    = $envelope->subject;
            $timestamp  = $envelope->date->getTimestamp();

            $plainpartid = $structure->findBody('plain');
            $htmlpartid  = $structure->findBody('html');

            $id = new \Horde_Imap_Client_Ids($message->getUid());

            $typemap = $structure->contentTypeMap();

            $html_data = $plain_data = '';
            $has_attachment = 0;
            $attachments = [];

            $htmlquery = new \Horde_Imap_Client_Fetch_Query();
            $htmlquery->bodyPart($htmlpartid);

            $htmlmessagedata = $oClient->fetch(
                $mailbox, $htmlquery, array('ids' => $id)
            )->first();
            if (!empty($htmlmessagedata)) {
                $htmlstream = $htmlmessagedata->getBodyPart($htmlpartid, true);

                $htmldata = $structure->getPart($htmlpartid);
                $htmldata->setContents($htmlstream, array('usestream' => true));
                $html_data = $htmldata->getContents();
            }

            if (empty($html_data)) {
                $plainquery = new \Horde_Imap_Client_Fetch_Query();
                $plainquery->bodyPart($plainpartid);

                $plainmsgdata = $oClient->fetch(
                    $mailbox, $plainquery, array('ids' => $id)
                )->first();
                if (!empty($plainmsgdata)) {
                    $plainstream = $plainmsgdata->getBodyPart($plainpartid, true);

                    $plaindata = $structure->getPart($htmlpartid);
                    $plaindata->setContents($plainstream, ['usestream' => true]);
                    $html_data = $plaindata->getContents();
                }
            }

            foreach ($typemap as $part => $type) {
                if (!in_array($part, [$htmlpartid, $plainpartid])) {
                    $partdata = $structure->getPart($part);
                    if ($file_name = $partdata->getName($part)) {
                        $has_attachment = 1;
                        $byte_size = $partdata->getBytes();
                        $attachments[] = [
                            "file" => $file_name,
                            "type" => $partdata->getType(),
                            "size" => $this->_humanFileSize($byte_size),
                            "part_id" => $part
                        ];
                    }
                }
            }

            $results[] = [
                'uid' => implode("", $id->ids),
                'from' => implode(",", $senders),
                'cc' => implode(",", $cc),
                'bcc' => implode(",", $bcc),
                'to' => implode(",", $recipients),
                'date' => $timestamp,
                'subject' => $subject,
                'body' => $html_data,
                'hasAttachments' => $has_attachment,
                'folder' => $mailbox,
                'messageId' =>  $envelope->message_id,
                'attachment' => $attachments
            ];
        }

        Log::info(
            "Iterate over messages and return the data in a formatted way",
            ['file' => __FILE__, 'line' => __LINE__]
        );

        return response()->json($results, 200);

    }

    /**
     * Download Attachment
     *
     * @param Request $request Laravel Request
     *
     * @return \Illuminate\Http\Response
     * @throws \Horde_Imap_Client_Exception
     * @throws \Horde_Imap_Client_Exception_NoSupportExtension
     */
    public function downloadAttachment(Request $request): \Illuminate\Http\Response
    {
        $oClient = $this->getIMAPCredential();

        $mailbox = $request->input("mailbox");
        $file_name = $request->input("file_name");
        $part_id = $request->input("part_id");
        $mail_uid = $request->input("mail_uid");

        $thread_id = new \Horde_Imap_Client_Ids($mail_uid);

        $query = new \Horde_Imap_Client_Fetch_Query();
        $query->structure();

        $messages = $oClient->fetch($mailbox, $query, ['ids' => $thread_id]);
        $file_content = $file_type = '';

        foreach ($messages as $message) {
            $structure = $message->getStructure();

            $partdata = $structure->getPart($part_id);

            if ($mail_file_name = $partdata->getName($part_id)) {
                if ($mail_file_name == $file_name) {
                    $contentquery = new \Horde_Imap_Client_Fetch_Query();
                    $contentquery->fullText();
                    $contentquery->bodyPart(
                        $part_id, ['decode' => true, 'peek' => true]
                    );
                    $contentquery->bodyPartSize($part_id);
                    $filedata = $oClient->fetch(
                        $mailbox, $contentquery, ['ids' => $thread_id]
                    )->first();
                    if (!empty($filedata)) {
                        $stream = $filedata->getBodyPart($part_id, true);
                        $partdata->setContents($stream, ['usestream' => true]);

                        $file_type = $partdata->getType();
                        $file_content = $partdata->getContents();
                    }
                }
            }
        }

        return response(
            $file_content, 200, [
                'Content-type'        => $file_type,
                'Content-Disposition' => 'attachment; filename="' . $file_name . '"',
            ]
        );
    }

    /**
     * Converts Byte to Human readable Size
     *
     * @param int $bytes    Size in bytes
     * @param int $decimals Decimal Count
     *
     * @return string
     */
    private function _humanFileSize($bytes, $decimals = 2): string
    {
        for ($i = 0; ($bytes / 1024) > 0.9; $i++, $bytes /= 1024) {
        }
        $unit = ['B','kB','MB','GB','TB','PB','EB','ZB','YB'][$i];
        return round($bytes, $decimals).$unit;
    }


    /*start of debug object code*/
    // return an array of superclasses
    /**
     * Get Lineage
     *
     * @param $object Object
     *
     * @return array|mixed
     * @throws \ReflectionException
     */
    private function _getLineage($object)
    {
        $reflection = new \ReflectionClass($object);

        if ($reflection->getParentClass()) {
            $parent = $reflection->getParentClass();

            $lineage = $this->_getLineage($parent);
            $lineage[] = $reflection->getName();
        } else {
            $lineage = array($reflection->getName());
        }

        return $lineage;
    }

    /**
     * Parent object to get children from
     *
     * @param $object parent object
     *
     * @return array
     * @throws \ReflectionException
     */
    private function _getChildClasses($object)
    {
        $reflection = new \ReflectionClass($object);

        $classes = get_declared_classes();

        $children = array();

        foreach ($classes as $class) {
            $checkedReflection = new \ReflectionClass($class);

            if ($checkedReflection->isSubclassOf($reflection->getName())) {
                $children[] = $checkedReflection->getName();
            }
        }

        return $children;
    }

    /**
     * Extract callable methods from object
     *
     * @param $object Object
     *
     * @return \ReflectionMethod[]
     * @throws \ReflectionException
     */
    private function _getCallableMethods($object)
    {
        $reflection = new \ReflectionClass($object);
        return $reflection->getMethods();
    }

    /**
     * Extract Properties from Object
     *
     * @param $object Object
     *
     * @return \ReflectionProperty[]
     * @throws \ReflectionException
     */
    private function _getProperties($object)
    {
        $reflection = new \ReflectionClass($object);
        return $reflection->_getProperties();
    }

    /**
     * Debug Object
     *
     * @param $object Object
     *
     * @return void
     * @throws \ReflectionException
     */
    private function _debugObject($object)
    {
        $reflection = new \ReflectionClass($object);
        echo "<h2>Class</h2>";
        echo "<p>{$reflection->getName()}</p>";
        echo "<h2>Inheritance</h2>";
        echo "<h3>Parents</h3>";

        $lineage = $this->_getLineage($object);
        array_pop($lineage);
        if (count($lineage) > 0) {
            echo "<p>" . join(" -&gt; ", $lineage) . "</p>";
        } else {
            echo "<i>None</i>";
        }

        echo "<h3>Children</h3>";

        $children = $this->_getChildClasses($object);
        echo "<p>";
        if (count($children) > 0) {
            echo join(', ', $children);
        } else {
            echo "<i>None</i>";
        }

        echo "</p>";
        echo "<h2>Methods</h2>";
        $methods = $this->_getCallableMethods($object);
        if (!count($methods)) {
            echo "<i>None</i><br />";
        } else {
            foreach ($methods as $method) {
                echo "<b>{$method}</b>();<br />";
            }
        }

        echo "<h2>Properties</h2>";
        $properties = $this->_getProperties($object);
        if (!count($properties)) {
            echo "<i>None</i><br />";
        } else {
            foreach (array_keys($properties) as $property) {
                echo "<b>\${$property}</b> = " . $object->$property . "<br />";
            }
        }
        echo "<hr />";

        exit;
    }
    /*end of debug object code*/
}
