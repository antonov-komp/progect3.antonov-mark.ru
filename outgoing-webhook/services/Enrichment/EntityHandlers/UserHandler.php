<?php
declare(strict_types=1);

class UserHandler extends DefaultEntityHandler
{
    public function __construct()
    {
        parent::__construct('user');
    }
}
