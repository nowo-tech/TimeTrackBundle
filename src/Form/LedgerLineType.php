<?php

declare(strict_types=1);

namespace Nowo\TimeTrackBundle\Form;

use Nowo\FormKitBundle\Attribute\FormKitConfig;
use Nowo\FormKitBundle\Form\FormOptionsTrait;
use Nowo\TimeTrackBundle\Enum\LedgerKind;
use Nowo\TimeTrackBundle\WorkingTime\BalanceLedger;
use Override;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\ChoiceType;
use Symfony\Component\Form\Extension\Core\Type\DateType;
use Symfony\Component\Form\Extension\Core\Type\HiddenType;
use Symfony\Component\Form\Extension\Core\Type\IntegerType;
use Symfony\Component\Form\Extension\Core\Type\TextareaType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\Form\FormTypeInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;

/**
 * Since 1.13: a balance ledger line (adjustment dialog, or a reversal with `reversal: true`): kind, signed minutes,
 * effective date, reason, and the reversed line. CSRF id `nowo_time_track_ledger`; rules and permissions are checked by
 * {@see BalanceLedger}.
 *
 * @extends AbstractType<array<string, mixed>>
 */
#[FormKitConfig(TimerActionType::FORM_KIT_PROFILE)]
final class LedgerLineType extends AbstractType
{
    use FormOptionsTrait;

    public const CSRF_TOKEN_ID = 'nowo_time_track_ledger';

    /** Largest absolute number of minutes of one line (one year, as {@see BalanceLedger::MAX_SECONDS}; checked by the handler). */
    public const MAX_MINUTES = 527_040;

    /** Kinds a person may add by hand (`overtime_ratified` is written by approvals only). */
    public const MANUAL_KINDS = [LedgerKind::Opening, LedgerKind::Adjustment, LedgerKind::OvertimePaid, LedgerKind::OvertimeCompensated];

    /**
     * @param FormBuilderInterface<mixed> $builder
     * @param array<string, mixed> $options
     */
    #[Override]
    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        if ($options['reversal'] === true) {
            $this->field($builder, 'reverses', HiddenType::class, ['label' => null]);
        } else {
            $choices = [];
            foreach (self::MANUAL_KINDS as $kind) {
                $choices['ledger.kind.' . $kind->value] = $kind->value;
            }
            $this->field($builder, 'kind', ChoiceType::class, ['label' => 'ledger.kind_label', 'choices' => $choices]);
            $this->field($builder, 'minutes', IntegerType::class, ['label' => 'ledger.minutes', 'help' => 'ledger.minutes_help', 'attr' => ['min' => -self::MAX_MINUTES, 'max' => self::MAX_MINUTES]]);
        }
        $this->field($builder, 'effective_date', DateType::class, ['label' => 'ledger.effective_date', 'widget' => 'single_text', 'input' => 'string']);
        $this->field($builder, 'reason', TextareaType::class, ['label' => 'ledger.reason', 'required' => true, 'attr' => ['maxlength' => 500, 'rows' => 3]]);
    }

    /**
     * CSRF id, the FormKit profile, `reversal` (false).
     */
    #[Override]
    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults([
            'method'             => 'POST',
            'csrf_protection'    => true,
            'csrf_field_name'    => '_token',
            'csrf_token_id'      => self::CSRF_TOKEN_ID,
            'reversal'           => false,
            'translation_domain' => 'NowoTimeTrackBundle',
        ]);
        $resolver->setAllowedTypes('reversal', 'bool');
    }

    /**
     * @param FormBuilderInterface<mixed> $builder
     * @param class-string<FormTypeInterface<mixed>> $type
     * @param array<string, mixed> $options
     */
    private function field(FormBuilderInterface $builder, string $name, string $type, array $options): void
    {
        if (!isset($this->formOptionsMerger)) {
            $builder->add($name, $type, $options);

            return;
        }
        $this->addWithDefaults($builder, $name, $type, $options);
    }
}
