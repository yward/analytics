<?php
/**
 * Analytics
 *
 * This file is licensed under the Affero General Public License version 3 or
 * later. See the LICENSE.md file.
 *
 * @author Marcel Scherello <audioplayer@scherello.de>
 * @copyright 2020 Marcel Scherello
 */

namespace OCA\Analytics\BackgroundJob;

use OCA\Analytics\Service\DataloadService;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\BackgroundJob\TimedJob;
use OCP\ILogger;

class Daily extends TimedJob
{

    private $logger;
    private $DataloadService;

    public function __construct(ITimeFactory $time,
                                ILogger $logger,
                                DataloadService $DataloadService
    )
    {
        parent::__construct($time);
        $this->setInterval((60 * 60 * 24) - 120); // 2 minutes because exact times would drift to the next cron execution
        $this->logger = $logger;
        $this->DataloadService = $DataloadService;
    }

    public function run($arguments)
    {
        //$this->logger->debug('Cron 38: Job started');
        try {
            $this->DataloadService->executeBySchedule('d');
        } catch (\Exception $e) {
            // no action
        }
    }

}