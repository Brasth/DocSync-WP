import * as Tabs from '@radix-ui/react-tabs';
import { createElement } from '@wordpress/element';

import {
  docSourceLabels,
  type SourceMode
} from './doc-source-modal-options';

type Props = {
  sourceMode: SourceMode;
  onChange: (mode: SourceMode) => void;
};

export const DocSourceTabs = ({ sourceMode, onChange }: Props): JSX.Element => {
  return (
    <Tabs.Root
      className="docsync-wp-source-tabs"
      onValueChange={(value) => onChange(value as SourceMode)}
      value={sourceMode}
    >
      <Tabs.List aria-label="Document source" className="docsync-wp-source-tabs__list">
        {(Object.keys(docSourceLabels) as SourceMode[]).map((mode) => (
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
