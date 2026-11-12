<?php

namespace App\Services;

use RuntimeException;

/**
 * VATSSA: the website JSON changed between loading the editor and saving it.
 */
class VatssaWebsiteConflict extends RuntimeException
{
    public function __construct()
    {
        parent::__construct('Someone else saved this page while you were editing. Your changes were not saved: reload the page and make them again.');
    }
}
