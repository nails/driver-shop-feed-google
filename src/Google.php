<?php

namespace Nails\Shop\Driver\Feed;

use Nails\Shop\Driver\FeedBase;

class Google extends FeedBase
{
    private $bIncludeTax = false;

    // --------------------------------------------------------------------------

    /**
     * Accepts an array of config values from the main driver model
     * @param array $aConfig The configs to set
     * @return array
     */
    public function setconfig($aConfig)
    {
        parent::setConfig($aConfig);
        $this->bIncludeTax = (bool) $aConfig['includeTax'];
    }
}
