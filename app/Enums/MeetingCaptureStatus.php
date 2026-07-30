<?php

namespace App\Enums;

enum MeetingCaptureStatus: string
{
    case Creating = 'creating';
    case Joining = 'joining';
    case WaitingRoom = 'waiting_room';
    case Active = 'active';
    case Stopping = 'stopping';
    case Ended = 'ended';
    case Failed = 'failed';
}
