<?php

namespace Kanvas\Notifications\Templates;

class Welcome extends Mail
{
    /**
     * Build the message.
     *
     * @return $this
     */
    public function build()
    {
        return $this->view('welcome-template');
    }
}
