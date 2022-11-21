<?php

    $_SERVER["DOCUMENT_ROOT"]='/home/bitrix/www';

    $mysql_host  = 'localhost';
    $mysql_name  = 'bitrix0';
    $mysql_pass  = '';
    $mysql_db    = 'sitemanager';


    define("NOT_CHECK_PERMISSIONS", true);
    require_once($_SERVER['DOCUMENT_ROOT'] . "/bitrix/modules/main/include/prolog_before.php");

    \Bitrix\Main\Loader::IncludeModule('main');
    \Bitrix\Main\Loader::IncludeModule('crm');
    \Bitrix\Main\Loader::IncludeModule('mail');
    global $DB;

    $mysqli = new mysqli($mysql_host, $mysql_name, $mysql_pass, $mysql_db);
    $mysqli->set_charset("utf8");
    
    if ($mysqli->connect_error) {
        die('Ошибка подключения (' . $mysqli->connect_errno . ') ' . $mysqli->connect_error);
    }

    function get_node($id_node, $node) {
        global $mysqli;

        $sql = 'SELECT * FROM `'.$node.'` WHERE `ID` = '.$id_node;
        $res = $mysqli->query($sql);
        return $res->fetch_assoc();
    }

    function getAliases($mailbox) {
        global $mysqli;

        $aliases = get_node($mailbox, 'b_mail_mailbox')['DESCRIPTION'];
        $aliases = explode("|", $aliases);
        $aliases[] = get_node($mailbox, 'b_mail_mailbox')['EMAIL'];
        return array_unique( $aliases );
    }

    function getDirection($aliases, $from_field) {
        global $mysqli;

        $type = "NONE";
        if ( in_array($from_field, $aliases) ) {
            $type = "OUT";
        } else {
            $type = "IN";
        }

        return $type;
    }

    function updateFileName($fileID, $newName) {
        global $mysqli;

        $bFile = get_node($fileID, "b_file");
        $newName = str_replace("'", "", $newName);

        if ($bFile['ORIGINAL_NAME'] != $newName) {
            $sql = "UPDATE `b_file` SET `ORIGINAL_NAME` = '".$newName."' WHERE `ID` = '".$fileID."';";
            $mysqli->query($sql);

            $ext = pathinfo($newName, PATHINFO_EXTENSION);

            $dir = "/home/bitrix/www/upload/";
            
            $result = false;
            $result = rename( 
                $dir . $bFile['SUBDIR'] . "/" . $bFile['FILE_NAME'],
                $dir . $bFile['SUBDIR'] . "/" . $bFile['FILE_NAME'] . "." . $ext
            );

            if ($result) {
                $sql = "UPDATE `b_file` SET `FILE_NAME` = '".$bFile['FILE_NAME'] . "." . $ext ."' WHERE `ID` = '".$fileID."';";
                $mysqli->query($sql);
            }
        }
    }

    function addCRMevent($entity_type, $entity_id, $message, $direction) {
        global $mysqli;
        
        $attachments = CMailAttachment::GetList(array(), array("MESSAGE_ID" => $message['ID']));
        $ids = [];

        while ($attachment = $attachments->Fetch() ) {
            $ids[] = $attachment['FILE_ID'];
            updateFileName($attachment['FILE_ID'], $attachment['FILE_NAME']);
        }

        $now = ConvertTimeStamp(time() + CTimeZone::GetOffset(), 'FULL', 's1');
        $arBindings[] = array(
                    'OWNER_TYPE_ID' => $entity_type,
                    'OWNER_ID' => $entity_id
                );

        $arFields = array(
          'OWNER_ID' => $entity_id,
          'OWNER_TYPE_ID' => $entity_type,
          'TYPE_ID' =>  CCrmActivityType::Email,
          'SUBJECT' => $message['SUBJECT'],
          'START_TIME' => $now,
          'END_TIME' => $now,
          'COMPLETED' => 'Y',
          'RESPONSIBLE_ID' => get_node($message['MAILBOX_ID'], 'b_mail_mailbox')['LINK'],
          'PRIORITY' => CCrmActivityPriority::Medium,
          'DESCRIPTION'      => ($message['BODY_HTML'] != NULL ? $message['BODY_HTML'] : $message['BODY']),
          'DESCRIPTION_TYPE' => ($message['BODY_HTML'] != NULL ? CCrmContentType::Html : CCrmContentType::PlainText ),
          'DIRECTION' => ($direction == "OUT" ? CCrmActivityDirection::Outgoing : CCrmActivityDirection::Incoming ),
          'LOCATION' => '',
          'NOTIFY_TYPE' => CCrmActivityNotifyType::None,
          'BINDINGS' => array_values($arBindings),
          'STORAGE_TYPE_ID' =>  1,
          'STORAGE_ELEMENT_IDS' => $ids
        );
        
         return CCrmActivity::Add($arFields, false, false, array('REGISTER_SONET_EVENT' => true));
    }

    function updateActivityParams($eventID, $entity_type, $entity_id, $addr, $name) {
        global $mysqli;

        $sql =  "INSERT INTO b_crm_act_comm (`ACTIVITY_ID`, `OWNER_ID`, `OWNER_TYPE_ID`, `TYPE`, `VALUE`, ".
                "`ENTITY_ID`, `ENTITY_TYPE_ID`, `ENTITY_SETTINGS`) VALUES ";
        $sql .= "( " . $eventID . "," .
                $entity_id      . ", " . 
                $entity_type    . ", " . 
                " 'EMAIL' "     . ", " .
                " '" . $addr    ."', " .
                $entity_id      . ", " . 
                $entity_type    . ", " . 
                " '" . serialize(array(
                    //'HONORIFIC'     => 0,
                    'NAME'          => $name,
                    //'SECOND_NAME'   => '',
                    //'LAST_NAME'     => '',
                    //'COMPANY_TITLE' => '',
                    //'COMPANY_ID'    => 0
                )) . "' "
                ." )";

        $mysqli->query($sql);
  
    }

    function updateMessage($ID, $Col, $val) {
        global $mysqli;

        $sql = "UPDATE `b_mail_message` SET `".$Col."` = '".$val."' WHERE `ID` = '".$ID."';";
        $mysqli->query($sql);
    }


    function getMessages() {
        global $mysqli;

        $sql = "SELECT * FROM `b_mail_message` WHERE `EXTERNAL_ID` IS NULL ORDER BY `ID` DESC LIMIT 1000";

        $res = $mysqli->query($sql);

        $pattern = '/[a-z0-9_\-\+\.]+@[a-z0-9\-]+\.([a-z]{2,4})(?:\.[a-z]{2})?/i';
        $c = 1;
        while ($message = $res->fetch_assoc()) {
            
            $message['FIELD_FROM_OLD'] = $message['FIELD_FROM'];
            $message['FIELD_TO_OLD']   = $message['FIELD_TO'];

            preg_match_all($pattern, $message['FIELD_FROM'], $message['FIELD_FROM_NEW']);
            preg_match_all($pattern, $message['FIELD_TO'], $message['FIELD_TO_NEW']);

            $message['FIELD_FROM'] = $message['FIELD_FROM_NEW'][0][0];
            $message['FIELD_TO']   = $message['FIELD_TO_NEW'][0][0];

            $aliases = getAliases(
                $message['MAILBOX_ID'] 
            );

            $direction = getDirection(
                $aliases, 
                $message['FIELD_FROM']
            );

            if ($direction == "OUT") {
                $addr = $message['FIELD_TO'];
                $name = trim( explode("<", $message['FIELD_TO_OLD'] )[0] );
            } else {
                $addr = $message['FIELD_FROM'];
                $name = trim( explode("<", $message['FIELD_FROM_OLD'] )[0] );
            }

            $sql = "SELECT * FROM `b_crm_dp_comm_mcd` WHERE `VALUE` = '".$addr."'";
            $result = $mysqli->query($sql);


            $processed = [];

            while ($row = $result->fetch_assoc()) {
                $eventID = addCRMevent($row['ENTITY_TYPE_ID'], $row['ENTITY_ID'], $message, $direction );
                updateActivityParams($eventID, $row['ENTITY_TYPE_ID'], $row['ENTITY_ID'], $addr, $name );

                $processed[] = $row['ENTITY_TYPE_ID'] . ":" . $row['ENTITY_ID'];

                if ($row['ENTITY_TYPE_ID'] == 3) {
                    $companyID = get_node($row['ENTITY_ID'], 'b_crm_contact' )['COMPANY_ID'];
                } else {
                    $companyID = NULL;
                }
                
                if ( $companyID != NULL && !in_array("4:" . $companyID, $processed) ) {
                    $eventID_com = addCRMevent(4, $companyID, $message, $direction );
                    updateActivityParams($eventID_com, 4, $companyID, $addr, $name );

                    $processed[] = "4:" . $companyID;
                }                

                updateMessage(
                    $message['ID'], 
                    "EXTERNAL_ID", 
                    serialize(array(
                        "EVENT_ID"          =>  $eventID,
                        "ENTITY_TYPE_ID"    =>  $row['ENTITY_TYPE_ID'],
                        "ENTITY_ID"         =>  $row['ENTITY_ID']
                    ))
                );

            }

            $subject = $message['SUBJECT'];
            $regEx = "/(?<=\[)[0-9]*?(?=])/";

            $deal = [];
            preg_match($regEx, $subject, $deal, PREG_OFFSET_CAPTURE);

            if ($deal[0][0] != NULL && $deal[0][0] > 0) {
                $eventID_deal = addCRMevent(2, $deal[0][0], $message, $direction );
                updateActivityParams($eventID_deal, 2, $deal[0][0], $addr, $name );
            }

    
        }

    }

    getMessages();
