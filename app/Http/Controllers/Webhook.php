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
        $this->SnackQuestionGateway = $snackQuestionGateway;

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


}
