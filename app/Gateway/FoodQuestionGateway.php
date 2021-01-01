<?php

namespace App\Gateway;

use Illuminate\Database\ConnectionInterface;

class FoodQuestionGateway
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
    function getFoodQuestion(int $questionNum)
    {
        $question = $this->db->table('food_questions')
            ->where('number', $questionNum)
            ->first();

        if ($question) {
            return (array) $question;
        }

        return null;
    }

    function isAnswerEqual(int $number, string $answer)
    {
        return $this->db->table('questions')
            ->where('number', $number)
            ->where('answer', $answer)
            ->exists();
    }
}
