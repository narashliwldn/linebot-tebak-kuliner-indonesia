<?php

namespace App\Http\Controllers;

use App\Gateway\EventLogGateway;
use App\Gateway\QuestionGateway;
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
     * @var QuestionGateway
     */
    private $questionGateway;
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
        QuestionGateway $questionGateway
    ) {
        $this->request = $request;
        $this->response = $response;
        $this->logger = $logger;
        $this->logGateway = $logGateway;
        $this->userGateway = $userGateway;
        $this->questionGateway = $questionGateway;

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

  private function textMessage($event)
{
   $userMessage = $event['message']['text'];
   if(/*$this->user['number'] == 0*/ true)
   {
       if(strtolower($userMessage) == 'mulai')
       {
         $carousel = new CarouselTemplateBuilder([
            new CarouselColumnTemplateBuilder(
              "Makanan",
              "Game menebak tentang makanan khas di daerah seluruh Indonesia",
              "https://cdn.idntimes.com/content-images/post/20181212/kuliner-indonessdsdia-87489b810390089e5d15cb5fbdc66865_600x400.jpg",
              [new MessageTemplateActionBuilder("Makanan", "makanan")]
            ),
            new CarouselColumnTemplateBuilder(
              "Snack/Kue",
              "Game menebak tentang jajanan khas di daerah seluruh Indonesia",
              "https://cdn.idntimes.com/content-images/post/20181212/kuliner-indonessdsdia-87489b810390089e5d15cb5fbdc66865_600x400.jpg",
              [new MessageTemplateActionBuilder("Snack/Kue", "snack")]
            )
          ]);

          $templateMessage = new TemplateMessageBuilder('Silahkan pilih game mana yang ingin dimainkan', $carousel);
          $this->bot->replyMessage($event['replyToken'], $templateMessage);

        // $httpClient = $httpClient = new CurlHTTPClient(getenv('CHANNEL_ACCESS_TOKEN'));
        // $carousel = file_get_contents("../carousel_message.json"); // template flex messagey
        // $result = $httpClient->post(LINEBot::DEFAULT_ENDPOINT_BASE . '/v2/bot/message/reply', [
        //             'replyToken' => $event['replyToken'],
        //             'messages'   => [
        //                     [
        //                       'type'     => 'flex',
        //                       'altText'  => 'Test Flex Message',
        //                       'contents' => json_decode($carousel)
        //                     ]
        //                   ],
        //               ]);
        //
        //   $response->getBody()->write($result->getJSONDecodedBody());
        //   return $response
        //       ->withHeader('Content-Type', 'application/json')
        //       ->withStatus($result->getHTTPStatus());

       }
       //jika memilih makanan
       elseif (strtolower($userMessage) == 'makanan') {
         // reset score
         $this->userGateway->setScore($this->user['user_id'], 0);
         // update number progress
         $this->userGateway->setUserProgress($this->user['user_id'], 1);
         // send question no.1 about food
         $this->sendQuestion($event['replyToken'], 'food', 1);
       }
       //jika memilih jajanan
       elseif (strtolower($userMessage) == 'kue/snack') {
         // reset score
         $this->userGateway->setScore($this->user['user_id'], 0);
         // update number progress
         $this->userGateway->setUserProgress($this->user['user_id'], 1);
         // send question no.1 about snack
         $this->sendQuestion($event['replyToken'], 'snack', 1);
       }

       else {
           $message = 'Silakan kirim pesan "MULAI" untuk memulai kuis.';
           $textMessageBuilder = new TextMessageBuilder($message);
           $this->bot->replyMessage($event['replyToken'], $textMessageBuilder);
       }

       // if user already begin test
   } else {
     if($category == 'food'){
       $this->checkAnswer('food', $userMessage, $event['replyToken']);
     }
     else {
        $this->checkAnswer('snack', $userMessage, $event['replyToken']);
     }
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

  private function sendQuestion($replyToken, $category, $questionNum=1)
{
    // get question from database
    $question = $this->questionGateway->getQuestion($category, $questionNum);

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

private function checkAnswer($category, $message, $replyToken)
{
    // if answer is true, increment score
    if($this->questionGateway->isAnswerEqual($category, $this->user['number'], $message)){
        $this->user['score']++;
        $this->userGateway->setScore($this->user['user_id'], $this->user['score']);
    }

    if($this->user['number'] < 9)
    {
        // update number progress
        $this->userGateway->setUserProgress($this->user['user_id'], $this->user['number'] + 1);

        // send next question
        $this->sendQuestion($replyToken, $this->user['number'] + 1);
    }
    else {
        // create user score message
        $message = 'Skormu '. $this->user['score'];
        $textMessageBuilder1 = new TextMessageBuilder($message);

        // create sticker message
        $stickerId = ($this->user['score'] < 6) ? 100 : 114;
        $stickerMessageBuilder = new StickerMessageBuilder(1, $stickerId);

        // create play again message
        $message = ($this->user['score'] < 6) ?
            'Wkwkwk! Nggak nyerah kan? Ketik "MULAI" untuk bermain lagi!':
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
