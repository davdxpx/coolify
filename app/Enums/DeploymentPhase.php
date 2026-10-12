<?php

namespace App\Enums;

enum DeploymentPhase: string
{
    case QUEUED = 'queued';
    case ADMITTED = 'admitted';
    case DISPATCHED = 'dispatched';
    case PREPARING = 'preparing';
    case BUILDING = 'building';
    case PUSHING = 'pushing';
    case DEPLOYING = 'deploying';
    case VERIFYING = 'verifying';
    case FINISHED = 'finished';
    case FAILED = 'failed';
    case CANCELLED = 'cancelled';

    public function isTerminal(): bool
    {
        return in_array($this, [self::FINISHED, self::FAILED, self::CANCELLED], true);
    }

    public function isWaiting(): bool
    {
        return in_array($this, [self::QUEUED, self::ADMITTED, self::DISPATCHED], true);
    }

    public function holdsCapacity(): bool
    {
        return ! $this->isTerminal() && $this !== self::QUEUED;
    }

    public function label(): string
    {
        return match ($this) {
            self::QUEUED => 'Queued',
            self::ADMITTED, self::DISPATCHED => 'Waiting for worker',
            self::PREPARING => 'Preparing',
            self::BUILDING => 'Building',
            self::PUSHING => 'Pushing image',
            self::DEPLOYING => 'Deploying',
            self::VERIFYING => 'Health check',
            self::FINISHED => 'Success',
            self::FAILED => 'Failed',
            self::CANCELLED => 'Cancelled',
        };
    }

    public function description(): string
    {
        return match ($this) {
            self::QUEUED => 'Waiting for a free deployment slot on the server.',
            self::ADMITTED => 'A deployment slot is reserved. The job is being handed to a worker.',
            self::DISPATCHED => 'A deployment slot is reserved. Waiting for a queue worker to pick up the job.',
            self::PREPARING => 'A worker started the deployment and prepares the source and helper container.',
            self::BUILDING => 'Building the image.',
            self::PUSHING => 'Pushing the image to the registry.',
            self::DEPLOYING => 'Starting the new containers.',
            self::VERIFYING => 'Waiting for the new containers to become healthy.',
            self::FINISHED => 'The deployment finished.',
            self::FAILED => 'The deployment failed.',
            self::CANCELLED => 'The deployment was cancelled.',
        };
    }
}
