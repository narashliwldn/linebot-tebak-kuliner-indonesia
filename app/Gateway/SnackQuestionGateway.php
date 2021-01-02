<?php

namespace App\Gateway;

use Illuminate\Database\ConnectionInterface;

class SnackQuestionGateway
{
    /**
     * @var ConnectionInterface
     */
    private $db;

    public function __construct()
    {
        $this->db = app('db');
    }

    // Question
    function getSnackQuestion(int $questionNum)
    {
        $question = $this->db->table('snack_questions')
            ->where('number', $questionNum)
            ->first();

        if ($question) {
            return (array) $question;
        }

        return null;
    }

    function isAnswerEqual(int $number, string $answer)
    {
        return $this->db->table('snack_questions')
            ->where('number', $number)
            ->where('answer', $answer)
            ->exists();
    }
}
