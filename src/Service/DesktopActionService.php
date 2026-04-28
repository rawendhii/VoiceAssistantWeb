<?php

namespace App\Service;

use App\Entity\DesktopAction;
use App\Entity\User;
use Doctrine\ORM\EntityManagerInterface;

class DesktopActionService
{
    public function __construct(
        private EntityManagerInterface $em,
        private string $desktopDeviceToken

    ) {
    }

    public function createFileAction(User $user, string $fileName, ?string $content = null): DesktopAction
    {
        $action = new DesktopAction();
        $action->setUser($user);
        $action->setDeviceToken($this->desktopDeviceToken);
        $action->setAction('CREATE_FILE');
        $action->setFileName($this->normalizeFileName($fileName));
        $action->setContent($content);
        $action->setStatus('PENDING');
        $action->setCreatedAt(new \DateTimeImmutable());

        $this->em->persist($action);
        $this->em->flush();

        return $action;
    }

    public function deleteFileAction(User $user, string $fileName): DesktopAction
    {
        $action = new DesktopAction();
        $action->setUser($user);
        $action->setDeviceToken($this->desktopDeviceToken);
        $action->setAction('DELETE_FILE');
        $action->setFileName($this->normalizeFileName($fileName));
        $action->setStatus('PENDING');
        $action->setCreatedAt(new \DateTimeImmutable());

        $this->em->persist($action);
        $this->em->flush();

        return $action;
    }

    public function renameFileAction(User $user, string $oldFileName, string $newFileName): DesktopAction
    {
        $action = new DesktopAction();
        $action->setUser($user);
        $action->setDeviceToken($this->desktopDeviceToken);
        $action->setAction('RENAME_FILE');
        $action->setFileName($this->normalizeFileName($oldFileName));
        $action->setNewFileName($this->normalizeFileName($newFileName));
        $action->setStatus('PENDING');
        $action->setCreatedAt(new \DateTimeImmutable());

        $this->em->persist($action);
        $this->em->flush();

        return $action;
    }

    public function readFileAction(User $user, string $fileName): DesktopAction
    {
        $action = new DesktopAction();
        $action->setUser($user);
        $action->setAction('READ_FILE');
        $action->setDeviceToken($this->desktopDeviceToken);
        $action->setFileName($this->normalizeFileName($fileName));
        $action->setStatus('PENDING');
        $action->setCreatedAt(new \DateTimeImmutable());

        $this->em->persist($action);
        $this->em->flush();

        return $action;
    }

    private function normalizeFileName(string $fileName): string
    {
        $fileName = trim($fileName);

        $fileName = str_replace(['\\', '/', '..', ':', '*', '?', '"', '<', '>', '|'], '', $fileName);

        if ($fileName === '') {
            $fileName = 'untitled';
        }

        if (!str_contains($fileName, '.')) {
            $fileName .= '.txt';
        }

        return $fileName;
    }
}