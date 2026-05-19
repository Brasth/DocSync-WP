import { createElement } from '@wordpress/element';

import { docSourceHelp } from './doc-source-modal-options';

type Props = {
  javascriptOrigin: string;
  pickerReady: boolean;
};

export const DocSourcePickerPanel = ({ javascriptOrigin, pickerReady }: Props): JSX.Element => {
  return (
    <div className="docsync-wp-picker-panel">
      <strong>Choose with Google Picker</strong>
      <p>{docSourceHelp.picker}</p>
      <p className="docsync-wp-picker-origin">
        If Google says no registered origin, add <code>{javascriptOrigin}</code> to the OAuth client Authorized JavaScript origins.
      </p>
      {!pickerReady ? <p className="docsync-wp-inline-warning">Finish Picker API key, Picker app ID, and OAuth client ID setup first.</p> : null}
    </div>
  );
};
