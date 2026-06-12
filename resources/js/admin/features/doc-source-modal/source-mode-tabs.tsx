import * as Tabs from '@radix-ui/react-tabs';
import { createElement } from '@wordpress/element';
import { __ } from '@wordpress/i18n';

import { docSourceLabels, type DocSourceUiMode } from './doc-source-modal-options';

type Props<T extends DocSourceUiMode> = {
  sourceMode: T;
  onChange: (mode: T) => void;
  modes: T[];
};

export const SourceModeTabs = <T extends DocSourceUiMode>({ sourceMode, onChange, modes }: Props<T>): JSX.Element => {
  return (
    <Tabs.Root
      className="docsync-wp-source-tabs"
      onValueChange={(value) => onChange(value as T)}
      value={sourceMode}
    >
      <Tabs.List aria-label={__('Document source', 'brasth-document-sync-for-google-docs')} className="docsync-wp-source-tabs__list">
        {modes.map((mode) => (
          <Tabs.Trigger
            className="docsync-wp-source-tabs__trigger"
            key={mode}
            value={mode}
          >
            {docSourceLabels[mode]}
          </Tabs.Trigger>
        ))}
      </Tabs.List>
    </Tabs.Root>
  );
};
