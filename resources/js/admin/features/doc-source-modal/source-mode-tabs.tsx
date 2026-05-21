import * as Tabs from '@radix-ui/react-tabs';
import { createElement } from '@wordpress/element';
import { __ } from '@wordpress/i18n';

import { docSourceLabels, type SourceMode } from './doc-source-modal-options';

type Props = {
  sourceMode: SourceMode;
  onChange: (mode: SourceMode) => void;
  modes?: SourceMode[];
};

export const SourceModeTabs = ({ sourceMode, onChange, modes }: Props): JSX.Element => {
  const availableModes = modes ?? (Object.keys(docSourceLabels) as SourceMode[]);

  return (
    <Tabs.Root
      className="docsync-wp-source-tabs"
      onValueChange={(value) => onChange(value as SourceMode)}
      value={sourceMode}
    >
      <Tabs.List aria-label={__('Document source', 'docsync-wp')} className="docsync-wp-source-tabs__list">
        {availableModes.map((mode) => (
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
