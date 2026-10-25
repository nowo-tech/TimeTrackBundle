<?php

declare(strict_types=1);

namespace Nowo\TimeTrackBundle\Form;

use Override;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\HiddenType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\Form\FormInterface;
use Symfony\Component\Form\FormView;
use Symfony\Component\OptionsResolver\OptionsResolver;

/**
 * Start/stop timer action of the manage UI (REQ-TWIG-005).
 *
 * Posts the same flat field names the controller always read: `_token`, `action` and `task_id`
 * (empty block prefix, created with an empty name). `id_suffix` keeps HTML ids unique when one
 * form is rendered per task row.
 *
 * @extends AbstractType<array{action?: string|null, task_id?: string|null}>
 */
final class TimerActionType extends AbstractType
{
    public const CSRF_TOKEN_ID = 'nowo_time_track_manage';

    /**
     * @param FormBuilderInterface<mixed> $builder
     * @param array<string, mixed> $options
     */
    #[Override]
    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $builder
            ->add('action', HiddenType::class)
            ->add('task_id', HiddenType::class);
    }

    /**
     * @param FormView<mixed> $view
     * @param FormInterface<mixed> $form
     * @param array<string, mixed> $options
     */
    #[Override]
    public function finishView(FormView $view, FormInterface $form, array $options): void
    {
        $suffix = (string) $options['id_suffix'];
        if ($suffix === '') {
            return;
        }

        foreach ($view->children as $child) {
            $child->vars['id'] .= '_' . $suffix;
        }
    }

    #[Override]
    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults([
            'method'          => 'POST',
            'csrf_protection' => true,
            'csrf_field_name' => '_token',
            'csrf_token_id'   => self::CSRF_TOKEN_ID,
            'id_suffix'       => '',
        ]);
        $resolver->setAllowedTypes('id_suffix', 'string');
    }

    #[Override]
    public function getBlockPrefix(): string
    {
        return '';
    }
}
