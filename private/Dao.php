<?php
    require_once("load_env.php");

    enum QueryResult {
        case SUCCESS;
        case FAILED_UNKNOWN;
        case FAILED_EMAIL_NOT_UNIQUE;
        case FAILED_USER_NOT_UNIQUE;
    }

    class Dao {
        public function getConnection() {
            $db = parse_url(getenv("DATABASE_URL"));
            return new PDO("pgsql:" . sprintf(
                "host=%s;port=%s;user=%s;password=%s;dbname=%s",
                $db["host"],
                $db["port"],
                $db["user"],
                $db["pass"],
                ltrim($db["path"], "/")
            ));
        }

        public function getUsers() {
            $conn = $this->getConnection();
            $result = $conn->query("SELECT username, display_name from users");
            return $result->fetchAll(PDO::FETCH_ASSOC);
        }

        /**
         * If correct, returns ['correct'=>true, 'user_id', 'username']
         * 
         * If not, returns ['correct'=>false]
         */
        public function verifyPassword($username, $password) {
            $conn = $this->getConnection();
            $result = $conn->query("SELECT passhash, id, username FROM users WHERE username = '$username' LIMIT 1;")->fetch();
            if ($result) {
                if (password_verify($password, $result['passhash'])) {
                    return ['correct'=>true, 'user_id'=>$result['id'], 'username'=>$result['username']];
                } else {
                    return ['correct'=>false];
                }
            } else {
                return ['correct'=>false];
            }
        }

        /**
         * Returns 
         *  QueryResult::SUCCESS on success
         *  QueryResult::FAILED_UNKNOWN
         *  QueryResult::FAILED_EMAIL_NOT_UNIQUE on bad email
         *  QueryResult::FAILED_USER_NOT_UNIQUE on bad user
         */
        public function createUser($username, $password, $email) {
            $conn = $this->getConnection();
            $name_check = $conn->query("SELECT * FROM users WHERE username = '$username' LIMIT 1;")->fetch();

            if ($name_check) {
                return QueryResult::FAILED_USER_NOT_UNIQUE;
            }

            $insertQuery =
                "INSERT INTO users
                (username, passhash, email)
                VALUES
                (:username, :passhash, :email)";
            $q = $conn->prepare($insertQuery);
            $q->bindParam(":username", $username);
            $q->bindParam(":email", $email);
            $passhash = password_hash($password, PASSWORD_DEFAULT);
            $q->bindParam(":passhash", $passhash);

            try{
                if($q->execute()) {
                    return QueryResult::SUCCESS;
                } else {
                    return QueryResult::FAILED_UNKNOWN;
                }
            } catch (PDOException) {
                if ($q->errorCode() == '23505') {
                    return QueryResult::FAILED_EMAIL_NOT_UNIQUE;
                }
            }
        }

        public function getLadders($user_id) {
            $conn = $this->getConnection();

            $q = $conn->prepare("SELECT 
                                    ladders.title AS ladder_title,
                                    ladders.id    AS ladder_id
                                 FROM     placements
                                     JOIN ladders
                                         ON placements.ladder = ladders.id
                                 WHERE  placements.player = :user_id;  ");

            $q->bindParam(":user_id", $user_id);
            
            if(!$q->execute()) {
                return QueryResult::FAILED_UNKNOWN;
            }

            return $q->fetchAll(PDO::FETCH_ASSOC);
        }

        public function getLadderTable($ladder_id) {
            $conn = $this->getConnection();

            $q = $conn->prepare("SELECT 
                                    users.username      AS username,
                                    placements.rank     AS rank,
                                    placements.wins     AS wins,
                                    placements.draws    AS draws,
                                    placements.losses   AS losses,
                                    placements.points   AS points
                                FROM   placements
                                    LEFT JOIN users
                                        ON placements.player = users.id
                                WHERE  placements.ladder = :ladder_id
                                ORDER BY placements.rank ASC");

            $q->bindParam(":ladder_id", $ladder_id);
            
            if(!$q->execute()) {
                return QueryResult::FAILED_UNKNOWN;
            }

            $q->execute();
            return $q->fetchAll(PDO::FETCH_ASSOC);
        }

        public function checkUserInTable ($user_id, $ladder_id) {
            $conn = $this->getConnection();

            $q = $conn->prepare("SELECT * FROM placements
                                WHERE   player = :user_id
                                AND     ladder = :ladder_id");

            $q->bindParam(":user_id",   $user_id);
            $q->bindParam(":ladder_id", $ladder_id);
            
            if(!$q->execute()) {
                return QueryResult::FAILED_UNKNOWN;
            }

            $r = $q->fetchAll();

            return !empty($r);
        }

        public function addUserToLadder ($user_id, $ladder_id) {
            $conn = $this->getConnection();

            $q = $conn->prepare("SELECT * FROM placements
                                WHERE   ladder = :ladder_id");
            $q->bindParam(":ladder_id", $ladder_id);
            if(!$q->execute())
                return false;
            $rank = count($q->fetchAll()) + 1;

            $q = $conn->prepare('INSERT INTO placements (player, ladder, rank)
                                 VALUES (:user_id, :ladder_id, :rank)');
            $q->bindParam(":ladder_id", $ladder_id);
            $q->bindParam(":user_id", $user_id);
            $q->bindParam(":rank", $rank);

            return $q->execute();
        }

        public function numInvites ($user_id) {
            $conn = $this->getConnection();

            $q = $conn->prepare('SELECT * FROM invites
                                 WHERE    recipient_id = :user_id');

            $q->bindParam(":user_id", $user_id);
            if(!$q->execute()) {
                return QueryResult::FAILED_UNKNOWN;
            }

            $r = $q->fetchAll(PDO::FETCH_ASSOC);

            return count($r);
        }

        public function numMatches ($user_id) {
            // $conn = $this->getConnection();

            // $q = $conn->prepare('SELECT * FROM invites
            //                      WHERE    recipient_id = :user_id');

            // $q->bindParam(":user_id", $user_id);
            // if(!$q->execute()) {
            //     return QueryResult::FAILED_UNKNOWN;
            // }

            // $r = $q->fetchAll(PDO::FETCH_ASSOC);

            // return count($r);

            return $user_id;
        }

        /**
         * Returns array:
         *  ['sender_name',
         *   'ladder_name',
         *   'invite_id']
         */
        public function getInvites($recipient_id) {
            $conn = $this->getConnection();

            $q = $conn->prepare("SELECT 
                                    sender.username     AS sender_name,
                                    ladders.title       AS ladder_name,
                                    invites.id          AS invite_id
                                FROM invites
                                    JOIN users AS sender
                                        ON invites.sender_id = sender.id
                                    JOIN ladders
                                        ON invites.ladder = ladders.id
                                WHERE invites.recipient_id = :recipient_id");

            $q->bindParam(":recipient_id", $recipient_id);
            
            if(!$q->execute()) {
                return QueryResult::FAILED_UNKNOWN;
            }

            $q->execute();
            return $q->fetchAll(PDO::FETCH_ASSOC);
        }

        public function getInvite($invite_id) {
            $conn = $this->getConnection();

            $q = $conn->prepare("SELECT sender_id,
                                        recipient_id,
                                        ladder AS ladder_id 
                                FROM invites WHERE id = :invite_id");

            $q->bindParam(":invite_id", $invite_id);
            
            if(!$q->execute()) {
                return QueryResult::FAILED_UNKNOWN;
            }

            $q->execute();
            return $q->fetch();
        }

        public function deleteInvite($invite_id) {
            $conn = $this->getConnection();
    
            $q = $conn->prepare('DELETE FROM invites WHERE id = :invite_id');

            $q->bindParam(":invite_id", $invite_id);

            return $q->execute();
        }
    }
