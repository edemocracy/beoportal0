<?
/********************************************************************************
 * The contents of this file are subject to the Common Public Attribution License
 * Version 1.0 (the "License"); you may not use this file except in compliance
 * with the License. You may obtain a copy of the License at
 * http://www.wikiarguments.net/license/. The License is based on the Mozilla
 * Public License Version 1.1 but Sections 14 and 15 have been added to cover
 * use of software over a computer network and provide for limited attribution
 * for the Original Developer. In addition, Exhibit A has been modified to be
 * consistent with Exhibit B.
 *
 * Software distributed under the License is distributed on an "AS IS" basis,
 * WITHOUT WARRANTY OF ANY KIND, either express or implied. See the License for
 * the specific language governing rights and limitations under the License.
 *
 * The Original Code is Wikiarguments. The Original Developer is the Initial
 * Developer and is Wikiarguments GbR. All portions of the code written by
 * Wikiarguments GbR are Copyright (c) 2012. All Rights Reserved.
 * Contributor(s):
 *     Andreas Wierz (andreas.wierz@gmail.com).
 *
 * Attribution Information
 * Attribution Phrase (not exceeding 10 words): Powered by Wikiarguments
 * Attribution URL: http://www.wikiarguments.net
 *
 * This display should be, at a minimum, the Attribution Phrase displayed in the
 * footer of the page and linked to the Attribution URL. The link to the Attribution
 * URL must not contain any form of 'nofollow' attribute.
 *
 * Display of Attribution Information is required in Larger Works which are
 * defined in the CPAL as a work which combines Covered Code or portions
 * thereof with code not governed by the terms of the CPAL.
 *******************************************************************************/

class PageProfile extends Page
{
    public function PageProfile($row)
    {
        global $sDB, $sRequest, $sQuery, $sTemplate, $sUser;
        parent::Page($row);

        $this->userId = $sRequest->getInt("userId");
        $this->user   = $sQuery->getUser("userId=".$this->userId);

        if($this->user != $sUser)
        {
            $sTemplate->error($sTemplate->getString("ERROR_INVALID_PROFILE"));
        }

        $this->setShortUrl($this->user->shortUrl());

        if($this->user == $sUser) {
            if($this->handleSubscribeUnsubscribe()) {
                header("Location: ".$this->getRedirectUrl());
                exit;
            }
        }
    }

    private function handleSubscribeUnsubscribe()
    {
        global $sRequest;
        global $sUser;
        global $sDB;
        global $sStatistics;
        //var_dump(@$_GET);
        //var_dump(@$_POST);

        /* 
         * allowed values for $subscribe / $unsubscribe: 
         * * 4 Interparteiliches
         * * 2 Politik
         * * 1 global
         */

        $userId = $this->user->getUserId();

        $subscribe = $sRequest->getInt("subscribe");
        if($subscribe)
        {
            
            error_log("user $userId subscribe $subscribe");
            if (!($subscribe == 4 | $subscribe == 2 | $subscribe == 1)) {
                exit;
            }
            $participation = $this->user->getParticipation();
            $newParticipation = $participation | $subscribe;
            $this->user->setParticipation($newParticipation);
            return true;
        } 

        $unsubscribe = $sRequest->getInt("unsubscribe");
        if($unsubscribe)
        {
            error_log("user $userId unsubscribe $unsubscribe");
            if ($unsubscribe == 1) {
                // global unsubscribe also kills other participation values and question upvotes
                $this->user->setParticipation(0);
                $sql = "SELECT questionId 
                        FROM user_votes
                        WHERE argumentId = '0'
                        AND userId = '$userId'";
                $res = $sDB->exec($sql);
                $questionIds = Array();
                while ($row = mysql_fetch_row($res)) {
                    array_push($questionIds, $row[0]);
                }
                
                $sql = "DELETE from user_votes 
                        WHERE userId = '$userId'
                        AND argumentId = '0'";
                $res = $sDB->exec($sql);

                foreach($questionIds as $questionId) {
                    $sStatistics->updateQuestionStats($questionId);
                }
                return true;
            }

            if (!($unsubscribe == 4 | $unsubscribe == 2)) {
                exit;
            }
            $upvoted = $this->upvotedQuestionsForParticipationValue($sUser->getUserId(), $unsubscribe);
            if ($upvoted) {
                exit;
            }
            $participation = $this->user->getParticipation();
            $newParticipation = $participation & ~$unsubscribe;
            $this->user->setParticipation($newParticipation);
            return true;
        }
    }

    public function getUserId()
    {
        return $this->userId;
    }

    public function getUser()
    {
        return $this->user;
    }

    public function title()
    {
        global $sTemplate;
        return $sTemplate->getString("HTML_META_TITLE_PROFILE",
                                     Array("[USERNAME]"),
                                     Array($this->getUser()->getUserName()));
    }

    public function getFollowedQuestions()
    {
        global $sDB, $sUser;

        $qF = Array();

        $res = $sDB->exec("SELECT `questions`.* FROM `notifications`
                          LEFT JOIN `questions` ON `questions`.questionId = `notifications`.`questionId`
                          LEFT JOIN `user_votes` ON questions.questionId = user_votes.questionId
                          WHERE `notifications`.`userId` = '".$this->userId."'
                          GROUP BY questions.questionId
                          ORDER BY argumentId, vote DESC;");
        while($row = mysql_fetch_object($res))
        {
            $q = new Question($row->questionId, $row);

            array_push($qF, $q);
        }

        return $qF;
    }

    private function makeSubscribeButton($label, $value) {
        return "<button class='button_orange' style='float:right; width: auto;' type='submit' name='subscribe' value=$value>Für '$label' anmelden</button>";
    }

    private function makeUnsubscribeButton($label, $value) {
        return "<button class='button_blue' style='float:right; width: auto;' type='submit' name='unsubscribe' value=$value>Von '$label' abmelden</button>";
    }

    private function upvotedQuestionsForParticipationValue($userId, $partValue) {
        global $sDB;
        $query = "SELECT count(*) as c
            FROM user_votes as v, questions as q
            WHERE v.questionId = q.questionId 
            AND argumentId=0 
            AND v.userId='$userId'
            AND q.participate & $partValue = $partValue";
        $res = $sDB->exec($query);
        $error = mysql_error();
        if ($error) var_dump($error);
        $row = mysql_fetch_array($res);
        return $row["c"];
    }

    private function makeParticipationRow($label, $value) {
        global $sUser;

        $participation = $sUser->getParticipation();

        if ($participation & $value) 
        {
            // check if user upvoted any question in this 'themenbereich'
            $upvoted = $this->upvotedQuestionsForParticipationValue($sUser->getUserId(), $value);
            if ($upvoted) {
                $content = "Du unterstützt $upvoted Anträge in diesem Themenbereich und bist damit automatisch Teilnehmer";
            } else {
                $content = "Du unterstützt keine Anträge in diesem Themenbereich ".$this->makeUnsubscribeButton($label, $value);
            }
        } else
        {
            $content = "Du unterstützt keine Anträge in diesem Themenbereich ".$this->makeSubscribeButton($label, $value);
        }
        return "$label: $content";
    }

    public function makeParticipationRowGlobal() {
        global $sUser;
        $label = "BEO";
        $value = 1;

        $participation = $sUser->getParticipation();

        if ($participation) 
        {
            $content = "Du bist global angemeldet. ".$this->makeUnsubscribeButton($label, $value);
            $content .= "<strong>Achtung: Bei einer Abmeldung werden alle Unterstützungen gelöscht! 
                    (Weitere Auswirkungen siehe Nutzungsbedingungen)</strong>";
        } else
        {
            $content = "Du bist abgemeldet. ".$this->makeSubscribeButton($label, $value);
        }
        return "$label: $content";
    }

    public function makeParticipationRowInnerparteiliches($user) {
        $label = "Innerparteiliches";
        $value = 4;
        return $this->makeParticipationRow($label, $value);
    }

    public function makeParticipationRowPolitik($user) {
        $label = "Politik";
        $value = 2;
        return $this->makeParticipationRow($label, $value);
    }

    public function getFormUrl()
    {
        global $sTemplate;
        return $sTemplate->getRoot()."user/".$this->userId."/";
    }

    public function getRedirectUrl() {
        global $sTemplate;
        return $sTemplate->getRoot()."user/".$this->userId."/";
    }

    private $userId;
    private $user;
};
?>
