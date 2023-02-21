<?php

use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;

require '../src/utils/make_response.php';

# Retrieve chats for a user
$app->get('/chats', function (Request $request, Response $response) {
    if(!$request->hasHeader('username')){
        return make_response(401,"Authentication missing",$response);
    }

    $username = $request->getHeader("username")[0];

    $pdo = new Db();
    $pdo = $pdo->connect();

    $stmt = $pdo->prepare('SELECT id FROM Users WHERE username = :username');
    $stmt->bindParam(':username', $username);
    $stmt->execute();
    $user_id = $stmt->fetchAll(PDO::FETCH_ASSOC);
    if(!$user_id){
        $response_body = ["code"=>401,"message"=>"User does not exists"];
        $response->getBody()->write(json_encode($response_body));
        return $response
        ->withStatus($response_body["code"]);
    }

    $stmt = $pdo->prepare('SELECT * FROM (SELECT d.id AS chat_id, user1 AS user1_id, user1_username, user2, username AS user2_username FROM (SELECT Chats.id, user2, user1, Users.username AS user1_username FROM Chats, Users WHERE Chats.user1=Users.id) d, Users WHERE d.user2=Users.id) z WHERE z.user1_username=? OR z.user2_username=?');
    $stmt->bindParam(1, $username);
    $stmt->bindParam(2, $username);
    $stmt->execute();
    $chats = $stmt->fetchAll(PDO::FETCH_ASSOC);
    $active_chats = array();

    # Add last message to each chat object
    foreach($chats as $chat){
        $chat_id = $chat["chat_id"];
        $stmt = $pdo->prepare('SELECT message, timestamp FROM Messages WHERE chat_id=? ORDER BY id DESC LIMIT 1');
        $stmt->bindParam(1, $chat_id);
        $stmt->execute();
        $last_message = $stmt->fetch();
        if($last_message){
            $chat += ["last_message" => $last_message["message"]];
            $chat += ["timestamp" => $last_message["timestamp"]];
            array_push($active_chats,$chat);
        }
    }

    $response->getBody()->write(json_encode($active_chats));
    return $response
        ->withStatus(200)
        ->withHeader("Content-Type","application/json");
    }
);

#Send message
$app->post('/chats/message/', function (Request $request, Response $response) {
    
    $parsedBody = $request->getBody();
    $parsedBody = json_decode($parsedBody,true);

    if(!array_key_exists("user_id",$parsedBody) || !array_key_exists("to",$parsedBody) || !array_key_exists("message",$parsedBody)){
        $response_body = ["code"=>401,"message"=>"Missing fields"];
        $response->getBody()->write(json_encode($response_body));
        return $response
        ->withStatus($response_body["code"]);
    }

    $user_id = $parsedBody["user_id"];
    $to = $parsedBody["to"];
    $message = $parsedBody["message"];

    $pdo = new Db();
    $pdo = $pdo->connect();
    $stmt = $pdo->prepare('SELECT id FROM Chats WHERE (user1=? AND user2=?) OR (user1=? AND user2=?)');
    $stmt->bindParam(1, $user_id);
    $stmt->bindParam(2, $to);
    $stmt->bindParam(3, $to);
    $stmt->bindParam(4, $user_id);
    $stmt->execute();
    $chat_id = $stmt->fetch(PDO::FETCH_ASSOC);

    # If previous conversation does not exists
    if(!$chat_id){
        
        #Create a new chat
        $stmt = $pdo->prepare('INSERT INTO Chats (user1,user2) VALUES(?, ?)');
        $stmt->bindParam(1, $user_id);
        $stmt->bindParam(2, $to);
        try{
            $stmt->execute();
            $chat_id = $pdo->lastInsertId();
        }catch(Exception $e){
            $response_body = ["code"=>401,"message"=>$e->getMessage()];
            $response->getBody()->write(json_encode($response_body));
            return $response
            ->withStatus($response_body["code"]);
        }

        #Add new message to the chat
        $stmt = $pdo->prepare('INSERT INTO Messages (chat_id,sender,message) VALUES(?, ?, ?)');
        $stmt->bindParam(1, $chat_id);
        $stmt->bindParam(2, $user_id);
        $stmt->bindParam(3, $message);
        try{
            $stmt->execute();
            $chat_id = $pdo->lastInsertId();
        }catch(Exception $e){
            $response_body = ["code"=>401,"message"=>$e->getMessage()];
            $response->getBody()->write(json_encode($response_body));
            return $response
            ->withStatus($response_body["code"]);
        }
        $response_body = ["code"=>200,"message"=>"Message sent"];
        $response->getBody()->write(json_encode($response_body));
        return $response
            ->withStatus($response_body["code"]);
    
    #If previous conversation exists
    }else{
        $chat_id = $chat_id["id"];

        #Add message to existing chat
        $stmt = $pdo->prepare('INSERT INTO Messages(chat_id,sender,message) VALUES(?, ?, ?)');
        $stmt->bindParam(1, $chat_id);
        $stmt->bindParam(2, $user_id);
        $stmt->bindParam(3, $message);
        try{
            $stmt->execute();
        }catch(Exception $e){
            $response_body = ["code"=>401,"message"=>$e->getMessage()];
            $response->getBody()->write(json_encode($response_body));
            return $response
            ->withStatus($response_body["code"]);
        }

        $response_body = ["code"=>200,"message"=>"Message sent"];
        $response->getBody()->write(json_encode($response_body));
        return $response
            ->withStatus($response_body["code"]);

        }
    }
);

# Retrieve messages in a chat
$app->get('/chats/{id}', function (Request $request, Response $response) {
   
    $chat_id = $request->getAttribute("id");

    $pdo = new Db();
    $pdo = $pdo->connect();

    #Check chat id
    $stmt = $pdo->prepare('SELECT * FROM Chats WHERE id= :chatid');
    $stmt->bindParam(':chatid', $chat_id);
    $stmt->execute();
    $chat = $stmt->fetch(PDO::FETCH_ASSOC);
    
    #If chat not found
    if(!$chat){
        $response_body = ["code"=>401,"message"=>"No chat found for this id."];
        $response->getBody()->write(json_encode($response_body));
        return $response
            ->withStatus($response_body["code"]);
    }

    #Get messages for chat
    $stmt = $pdo->prepare('SELECT id, timestamp, sender, message FROM Messages WHERE chat_id= :chatid ORDER BY id DESC');
    $stmt->bindParam(':chatid', $chat_id);
    $stmt->execute();
    $messages = $stmt->fetchAll(PDO::FETCH_ASSOC);

    #If no message exist
    if(!$messages){
        $response_body = ["code"=>401,"message"=>"No message found for this chat."];
        $response->getBody()->write(json_encode($response_body));
        return $response
            ->withStatus($response_body["code"]);
    }

    $response->getBody()->write(json_encode($messages));
    return $response
        ->withStatus(200)
        ->withHeader("Content-Type","application/json");
    }
);

