<?php

declare(strict_types=1);

return [
    Symfony\Bundle\FrameworkBundle\FrameworkBundle::class            => ['all' => true],
    Doctrine\Bundle\DoctrineBundle\DoctrineBundle::class             => ['all' => true],
    Doctrine\Bundle\MigrationsBundle\DoctrineMigrationsBundle::class => ['all' => true],
    Doctrine\Bundle\FixturesBundle\DoctrineFixturesBundle::class     => ['all' => true],
    Symfony\Bundle\SecurityBundle\SecurityBundle::class              => ['all' => true],
    Symfony\Bundle\TwigBundle\TwigBundle::class                      => ['all' => true],
    Nowo\TimeTrackBundle\TimeTrackBundle::class                      => ['all' => true],
    Nowo\TaskBoardBundle\TaskBoardBundle::class                      => ['all' => true],
    Nowo\TwigInspectorBundle\NowoTwigInspectorBundle::class          => ['dev' => true, 'test' => true],
    Symfony\Bundle\WebProfilerBundle\WebProfilerBundle::class        => ['dev' => true, 'test' => true],
    Symfony\Bundle\DebugBundle\DebugBundle::class                    => ['dev' => true],
    Nowo\TagInputBundle\NowoTagInputBundle::class                    => ['all' => true],
    Nowo\TiptapEditorBundle\NowoTiptapEditorBundle::class            => ['all' => true],
];
