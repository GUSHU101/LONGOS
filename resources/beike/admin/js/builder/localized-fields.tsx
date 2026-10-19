import { useId, useState } from "react";
import { languageMap, readLocalized, writeLocalized } from "./localized-value";

type FieldDefinition = {
  type?: string;
  label?: string;
  placeholder?: string;
  maxLength?: number;
  rows?: number;
  objectFields?: Record<string, FieldDefinition>;
  arrayFields?: Record<string, FieldDefinition>;
};

/** Preserve all translations while editing the current storefront language. */
export function localizedFields(fields: Record<string, FieldDefinition>, locale: string): Record<string, FieldDefinition> {
  return Object.fromEntries(Object.entries(fields).map(([key, field]) => {
    if (field.type === "text" || field.type === "textarea") return [key, { ...field, type: "custom", render: function LocalizedText({ value, onChange, readOnly }: { value: unknown; onChange: (value: unknown) => void; readOnly?: boolean }) {
      const id = useId();
      const [language, setLanguage] = useState(locale);
      const languages = languageMap(value) ? [...new Set([locale, ...Object.keys(value)])] : [];
      const current = readLocalized(value, language);
      const control = {
        id,
        value: current,
        disabled: readOnly,
        placeholder: field.placeholder,
        maxLength: field.maxLength,
        onChange: (event: React.ChangeEvent<HTMLInputElement | HTMLTextAreaElement>) => onChange(writeLocalized(value, language, event.target.value)),
      };
      return <div className="beike-puck-localized-field">
        <div className="beike-puck-localized-field__head"><label htmlFor={id}>{field.label || key}</label>{languages.length > 1 ? <select aria-label={`${field.label || key}语言`} value={language} disabled={readOnly} onChange={event => setLanguage(event.target.value)}>{languages.map(code => <option key={code} value={code}>{code}</option>)}</select> : null}</div>
        {field.type === "textarea" ? <textarea {...control} rows={field.rows || 4} /> : <input {...control} type="text" />}
        {field.maxLength ? <small className="beike-puck-localized-field__count" aria-live="polite">{current.length} / {field.maxLength}</small> : null}
      </div>;
    } }];
    return [key, { ...field, ...(field.objectFields ? { objectFields: localizedFields(field.objectFields, locale) } : {}), ...(field.arrayFields ? { arrayFields: localizedFields(field.arrayFields, locale) } : {}) }];
  }));
}
