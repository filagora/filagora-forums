<?php

declare(strict_types=1);

return [
    'refusals' => [
        'already_has_poll' => 'This thread already has a poll.',
        'has_votes' => 'A poll cannot be edited once someone has voted.',
        'closed' => 'This poll is closed.',
        'already_voted' => 'You have already voted in this poll.',
        'not_visible' => 'You cannot see this thread.',
        'invalid_answers' => 'Please answer every question, choosing options that belong to it.',
        'invalid_questions' => 'The poll\'s questions are not valid.',
        'wrong_state' => 'The poll is not in a state where that can be done.',
    ],

    'invalid' => [
        'question_count' => 'A poll needs between 1 and :max questions.',
        'prompt' => 'Question :number needs a prompt of up to :max characters.',
        'option_count' => 'Question :number needs between 2 and :max options.',
        'label' => 'Every option of question :number needs a label of up to :max characters.',
        'repeated' => 'The options of question :number must not repeat.',
    ],
];
