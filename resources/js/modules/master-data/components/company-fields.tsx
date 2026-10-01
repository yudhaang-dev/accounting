import { Controller, useFormContext } from 'react-hook-form';
import { useTranslation } from 'react-i18next';
import {
    Field,
    FieldDescription,
    FieldError,
    FieldLabel,
} from '@/components/ui/field';
import { Input } from '@/components/ui/input';
import { ToggleGroup, ToggleGroupItem } from '@/components/ui/toggle-group';

export type CompanyFormValues = {
    name: string;
    legal_name: string;
    tax_id: string;
    functional_currency: string;
    timezone: string;
    default_locale: 'id' | 'en';
};

type Props = {
    /** Legal name and tax ID are part of the profile, not of creating a workspace. */
    withLegalIdentity?: boolean;
    /** The functional currency is fixed once the company is active (MD-AC-05). */
    currencyLocked?: boolean;
};

const timezones = (): string[] => {
    try {
        return Intl.supportedValuesOf('timeZone');
    } catch {
        return [];
    }
};

/**
 * Company profile fields (MD-FR-01), shared by the workspace form and the setup wizard. Rendered
 * inside a react-hook-form provider whose values include `CompanyFormValues`.
 */
export function CompanyFields({ withLegalIdentity, currencyLocked }: Props) {
    const { t } = useTranslation();
    const {
        register,
        control,
        formState: { errors },
    } = useFormContext<CompanyFormValues>();

    const text = (
        name:
            | 'name'
            | 'legal_name'
            | 'tax_id'
            | 'functional_currency'
            | 'timezone',
    ) => ({
        id: `company-${name}`,
        'aria-invalid': errors[name] ? (true as const) : undefined,
    });

    return (
        <>
            <Field data-invalid={errors.name ? true : undefined}>
                <FieldLabel htmlFor="company-name">
                    {t('company.name')}
                </FieldLabel>
                <Input
                    {...text('name')}
                    autoComplete="organization"
                    {...register('name', { required: t('common.required') })}
                />
                {errors.name && <FieldError>{errors.name.message}</FieldError>}
            </Field>

            {withLegalIdentity && (
                <>
                    <Field data-invalid={errors.legal_name ? true : undefined}>
                        <FieldLabel htmlFor="company-legal_name">
                            {t('company.legalName')}
                        </FieldLabel>
                        <Input
                            {...text('legal_name')}
                            {...register('legal_name')}
                        />
                        {errors.legal_name && (
                            <FieldError>{errors.legal_name.message}</FieldError>
                        )}
                    </Field>
                    <Field data-invalid={errors.tax_id ? true : undefined}>
                        <FieldLabel htmlFor="company-tax_id">
                            {t('company.taxId')}
                        </FieldLabel>
                        <Input
                            {...text('tax_id')}
                            inputMode="numeric"
                            {...register('tax_id')}
                        />
                        {errors.tax_id && (
                            <FieldError>{errors.tax_id.message}</FieldError>
                        )}
                    </Field>
                </>
            )}

            <Field data-invalid={errors.functional_currency ? true : undefined}>
                <FieldLabel htmlFor="company-functional_currency">
                    {t('company.currency')}
                </FieldLabel>
                <Input
                    {...text('functional_currency')}
                    className="max-w-28 uppercase"
                    maxLength={3}
                    autoCapitalize="characters"
                    disabled={currencyLocked}
                    {...register('functional_currency', {
                        required: t('common.required'),
                        setValueAs: (value: string) =>
                            value.trim().toUpperCase(),
                    })}
                />
                <FieldDescription>{t('company.currencyHint')}</FieldDescription>
                {errors.functional_currency && (
                    <FieldError>
                        {errors.functional_currency.message}
                    </FieldError>
                )}
            </Field>

            <Field data-invalid={errors.timezone ? true : undefined}>
                <FieldLabel htmlFor="company-timezone">
                    {t('company.timezone')}
                </FieldLabel>
                <Input
                    {...text('timezone')}
                    list="company-timezones"
                    autoComplete="off"
                    {...register('timezone', {
                        required: t('common.required'),
                    })}
                />
                <datalist id="company-timezones">
                    {timezones().map((zone) => (
                        <option key={zone} value={zone} />
                    ))}
                </datalist>
                {errors.timezone && (
                    <FieldError>{errors.timezone.message}</FieldError>
                )}
            </Field>

            <Field>
                <FieldLabel id="company-locale-label">
                    {t('company.locale')}
                </FieldLabel>
                <Controller
                    control={control}
                    name="default_locale"
                    render={({ field }) => (
                        <ToggleGroup
                            variant="outline"
                            aria-labelledby="company-locale-label"
                            value={[field.value]}
                            onValueChange={(value) => {
                                const next = value[0];

                                if (next === 'id' || next === 'en') {
                                    field.onChange(next);
                                }
                            }}
                        >
                            <ToggleGroupItem value="id">
                                {t('company.localeId')}
                            </ToggleGroupItem>
                            <ToggleGroupItem value="en">
                                {t('company.localeEn')}
                            </ToggleGroupItem>
                        </ToggleGroup>
                    )}
                />
            </Field>
        </>
    );
}
