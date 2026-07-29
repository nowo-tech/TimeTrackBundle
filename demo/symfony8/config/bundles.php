<?php

declare(strict_types=1);
use Doctrine\Bundle\DoctrineBundle\DoctrineBundle;
use Doctrine\Bundle\FixturesBundle\DoctrineFixturesBundle;
use Doctrine\Bundle\MigrationsBundle\DoctrineMigrationsBundle;
use Nowo\TagInputBundle\NowoTagInputBundle;
use Nowo\TaskBoardBundle\TaskBoardBundle;
use Nowo\TimeTrackBundle\TimeTrackBundle;
use Nowo\TiptapEditorBundle\NowoTiptapEditorBundle;
use Nowo\TwigInspectorBundle\NowoTwigInspectorBundle;
use Symfony\Bundle\DebugBundle\DebugBundle;
use Symfony\Bundle\FrameworkBundle\FrameworkBundle;
use Symfony\Bundle\SecurityBundle\SecurityBundle;
use Symfony\Bundle\TwigBundle\TwigBundle;
use Symfony\Bundle\WebProfilerBundle\WebProfilerBundle;

return [
    FrameworkBundle::class          => ['all' => true],
    DoctrineBundle::class           => ['all' => true],
    DoctrineMigrationsBundle::class => ['all' => true],
    DoctrineFixturesBundle::class   => ['all' => true],
    SecurityBundle::class           => ['all' => true],
    TwigBundle::class               => ['all' => true],
    TimeTrackBundle::class          => ['all' => true],
    TaskBoardBundle::class          => ['all' => true],
    NowoTwigInspectorBundle::class  => ['dev' => true, 'test' => true],
    WebProfilerBundle::class        => ['dev' => true, 'test' => true],
    DebugBundle::class              => ['dev' => true],
    NowoTagInputBundle::class       => ['all' => true],
    NowoTiptapEditorBundle::class   => ['all' => true],
];
