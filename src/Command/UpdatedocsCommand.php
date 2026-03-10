<?php
namespace App\Command;

use Cake\Command\Command;
use Cake\Console\Arguments;
use Cake\Console\ConsoleIo;
use PHPUnit\Framework\Constraint\Count;
use SpaLiveV1\Controller\CourseController;


class UpdatedocsCommand extends Command{
    
    public function execute(Arguments $args, ConsoleIo $io){
        $C = new CourseController();
        $C->syncSeatsToGoogleDocs();

    }
}