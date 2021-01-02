<?php

namespace App\Http\Controllers;

use App\Gateway\EventLogGateway;
use App\Gateway\FoodQuestionGateway;
use App\Gateway\SnackQuestionGateway;
use App\Gateway\UserGateway;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Log\Logger;
use LINE\LINEBot;
use LINE\LINEBot\HTTPClient\CurlHTTPClient;
use LINE\LINEBot\MessageBuilder\MultiMessageBuilder;
use LINE\LINEBot\MessageBuilder\StickerMessageBuilder;
use LINE\LINEBot\MessageBuilder\TemplateBuilder\ButtonTemplateBuilder;
use LINE\LINEBot\MessageBuilder\TemplateMessageBuilder;
use LINE\LINEBot\MessageBuilder\TextMessageBuilder;
use LINE\LINEBot\MessageBuilder\TemplateBuilder\CarouselTemplateBuilder;
use LINE\LINEBot\MessageBuilder\TemplateBuilder\CarouselColumnTemplateBuilder;
use LINE\LINEBot\TemplateActionBuilder\MessageTemplateActionBuilder;

class Webhook extends Controller
{
    /**
     * @var LINEBot
     */
    private $bot;
    /**
     * @var Request
     */
    private $request;
    /**
     * @var Response
     */
    private $response;
    /**
     * @var Logger
     */
    private $logger;
    /**
     * @var EventLogGateway
     */
    private $logGateway;
    /**
     * @var UserGateway
     */
    private $userGateway;
    /**
     * @var FoodQuestionGateway
     */
    private $foodQuestionGateway;
    /**
     * @var SnackQuestionGateway
     */
    private $snackQuestionGateway;
    /**
     * @var array
     */
    private $user;


    public function __construct(
        Request $request,
        Response $response,
        Logger $logger,
        EventLogGateway $logGateway,
        UserGateway $userGateway,
        FoodQuestionGateway $foodQuestionGateway,
        SnackQuestionGateway $snackQuestionGateway
    ) {
        $this->request = $request;
        $this->response = $response;
        $this->logger = $logger;
        $this->logGateway = $logGateway;
        $this->userGateway = $userGateway;
        $this->foodQuestionGateway = $foodQuestionGateway;
        $this->snackQuestionGateway = $snackQuestionGateway;

        // create bot object
        $httpClient = new CurlHTTPClient(getenv('CHANNEL_ACCESS_TOKEN'));
        $this->bot  = new LINEBot($httpClient, ['channelSecret' => getenv('CHANNEL_SECRET')]);
    }

    public function __invoke(){
    // get request
    $body = $this->request->all();

    // debuging data
    $this->logger->debug('Body', $body);

    // save log
    $signature = $this->request->server('HTTP_X_LINE_SIGNATURE') ?: '-';
    $this->logGateway->saveLog($signature, json_encode($body, true));

    return $this->handleEvents();
  }

  private function handleEvents(){
    $data = $this->request->all();

    if(is_array($data['events'])){
        foreach ($data['events'] as $event)
        {
            // skip group and room event
            if(! isset($event['source']['userId'])) continue;

            // get user data from database
            $this->user = $this->userGateway->getUser($event['source']['userId']);

            // if user not registered
            if(!$this->user) $this->followCallback($event);
            else {
                // respond event
                if($event['type'] == 'message'){
                    if(method_exists($this, $event['message']['type'].'Message')){
                        $this->{$event['message']['type'].'Message'}($event);
                    }
                } else {
                    if(method_exists($this, $event['type'].'Callback')){
                        $this->{$event['type'].'Callback'}($event);
                    }
                }
            }
        }
    }


    $this->response->setContent("No events found!");
    $this->response->setStatusCode(200);
    return $this->response;
  }

  private function followCallback($event){
    $res = $this->bot->getProfile($event['source']['userId']);
    if ($res->isSucceeded()){
        $profile = $res->getJSONDecodedBody();

        // create welcome message
        $message  = "Halo! Salam kenal, " . $profile['displayName'] . "!\n";
        $message .= "Silakan kirim pesan \"MULAI\" untuk memulai kuis Tebak Kuliner Indonesia. Setelah kirim \"MULAI\", kamu akan diberi pilihan kuis apa yang mau dimainkan";
        $textMessageBuilder = new TextMessageBuilder($message);

        // create sticker message
        $stickerMessageBuilder = new StickerMessageBuilder(rand(1, 4), rand(1, 259));

        // merge all message
        $multiMessageBuilder = new MultiMessageBuilder();
        $multiMessageBuilder->add($textMessageBuilder);
        $multiMessageBuilder->add($stickerMessageBuilder);

        // send reply message
        $this->bot->replyMessage($event['replyToken'], $multiMessageBuilder);

        // save user data
        $this->userGateway->saveUser(
            $profile['userId'],
            $profile['displayName']
        );
    }
  }



  private function stickerMessage($event)
{
    // create sticker message
    $packageId = $event['message']['packageId'];
    $stickerId = $event['message']['stickerId'];
    $stickerMessageBuilder = new StickerMessageBuilder($packageId, $stickerId);

    // create text message
    $message = 'Silakan kirim pesan "MULAI" untuk memulai kuis.';
    $textMessageBuilder = new TextMessageBuilder($message);

    // merge all message
    $multiMessageBuilder = new MultiMessageBuilder();
    $multiMessageBuilder->add($stickerMessageBuilder);
    $multiMessageBuilder->add($textMessageBuilder);

    // send message
    $this->bot->replyMessage($event['replyToken'], $multiMessageBuilder);
  }

  private function sendFoodQuestion($replyToken, $questionNum=1)
{
    // get question from database
    $question = $this->foodQuestionGateway->getFoodQuestion($questionNum);

    // prepare answer options
    for($opsi = "a"; $opsi <= "d"; $opsi++) {
        if(!empty($question['option_'.$opsi]))
            $options[] = new MessageTemplateActionBuilder($question['option_'.$opsi], $question['option_'.$opsi]);
    }

    // prepare button template
    $buttonTemplate = new ButtonTemplateBuilder($question['number']."/9", $question['text'], $question['image'], $options);

    // build message
    $messageBuilder = new TemplateMessageBuilder("Gunakan mobile app untuk melihat soal", $buttonTemplate);

    // send message
    $response = $this->bot->replyMessage($replyToken, $messageBuilder);
}

private function sendSnackQuestion($replyToken, $questionNum=1)
{
  // get question from database
  $question = $this->snackQuestionGateway->getSnackQuestion($questionNum);

  // prepare answer options
  for($opsi = "a"; $opsi <= "d"; $opsi++) {
      if(!empty($question['option_'.$opsi]))
          $options[] = new MessageTemplateActionBuilder($question['option_'.$opsi], $question['option_'.$opsi]);
  }

  // prepare button template
  $buttonTemplate = new ButtonTemplateBuilder($question['number']."/9", $question['text'], $question['image'], $options);

  // build message
  $messageBuilder = new TemplateMessageBuilder("Gunakan mobile app untuk melihat soal", $buttonTemplate);

  // send message
  $response = $this->bot->replyMessage($replyToken, $messageBuilder);
}

private function checkFoodAnswer($message, $replyToken)
{
    // if answer is true, increment score
    if($this->foodQuestionGateway->isAnswerEqual($this->user['number'], $message)){
        $this->user['score']++;
        $this->userGateway->setScore($this->user['user_id'], $this->user['score']);
    }

    if($this->user['number'] < 9)
    {
        // update number progress
        $this->userGateway->setUserProgress($this->user['user_id'], $this->user['number'] + 1);

        // send next question
        $this->sendFoodQuestion($replyToken, $this->user['number'] + 1);
    }
    else {
        // create user score message
        $message = 'Skormu '. $this->user['score'];
        $textMessageBuilder1 = new TextMessageBuilder($message);

        // create sticker message
        $stickerId = ($this->user['score'] < 8) ? 100 : 114;
        $stickerMessageBuilder = new StickerMessageBuilder(1, $stickerId);

        // create play again message
        $message = ($this->user['score'] < 8) ?
            'Wkwkwk! Nyerah? Ketik "MULAI" untuk bermain lagi!':
            'Great! Mantap bro! Ketik "MULAI" untuk bermain lagi!';
        $textMessageBuilder2 = new TextMessageBuilder($message);

        // merge all message
        $multiMessageBuilder = new MultiMessageBuilder();
        $multiMessageBuilder->add($textMessageBuilder1);
        $multiMessageBuilder->add($stickerMessageBuilder);
        $multiMessageBuilder->add($textMessageBuilder2);

        // send reply message
        $this->bot->replyMessage($replyToken, $multiMessageBuilder);
        $this->userGateway->setUserProgress($this->user['user_id'], 0);
    }
  }

  private function checkSnackAnswer($message, $replyToken)
  {
      // if answer is true, increment score
      if($this->snackQuestionGateway->isAnswerEqual($this->user['number'], $message)){
          $this->user['score']++;
          $this->userGateway->setScore($this->user['user_id'], $this->user['score']);
      }

      if($this->user['number'] < 9)
      {
          // update number progress
          $this->userGateway->setUserProgress($this->user['user_id'], $this->user['number'] + 1);

          // send next question
          $this->sendSnackQuestion($replyToken, $this->user['number'] + 1);
      }
      else {
          // create user score message
          $message = 'Skormu '. $this->user['score'];
          $textMessageBuilder1 = new TextMessageBuilder($message);

          // create sticker message
          $stickerId = ($this->user['score'] < 8) ? 100 : 114;
          $stickerMessageBuilder = new StickerMessageBuilder(1, $stickerId);

          // create play again message
          $message = ($this->user['score'] < 8) ?
              'Wkwkwk! Nyerah? Ketik "MULAI" untuk bermain lagi!':
              'Great! Mantap bro! Ketik "MULAI" untuk bermain lagi!';
          $textMessageBuilder2 = new TextMessageBuilder($message);

          // merge all message
          $multiMessageBuilder = new MultiMessageBuilder();
          $multiMessageBuilder->add($textMessageBuilder1);
          $multiMessageBuilder->add($stickerMessageBuilder);
          $multiMessageBuilder->add($textMessageBuilder2);

          // send reply message
          $this->bot->replyMessage($replyToken, $multiMessageBuilder);
          $this->userGateway->setUserProgress($this->user['user_id'], 0);
      }
    }

}
