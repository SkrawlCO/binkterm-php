import { StrictMode } from 'react';
import { createRoot } from 'react-dom/client';

import { App } from './App';
import { initialize } from './bridge';
import './styles/index.css';

const container = document.getElementById('root');

if (!container) {
  throw new Error('Root container #root is missing from index.html');
}

initialize().then(() => createRoot(container).render(
  <StrictMode>
    <App />
  </StrictMode>,
)).catch(() => { container.textContent = "Unable to load Wordwright. Reload to retry."; });
