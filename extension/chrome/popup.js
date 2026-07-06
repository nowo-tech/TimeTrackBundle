/**
 * TimeTrack extension popup — Bearer API client.
 */
const statusEl = document.getElementById('status');
const loginView = document.getElementById('login-view');
const timerView = document.getElementById('timer-view');

function setStatus(msg) {
  statusEl.textContent = msg;
}

async function getConfig() {
  return chrome.storage.local.get(['server', 'token', 'email']);
}

async function api(path, options = {}) {
  const { server, token } = await getConfig();
  const headers = { 'Content-Type': 'application/json', ...(options.headers || {}) };
  if (token) headers.Authorization = `Bearer ${token}`;
  const res = await fetch(`${server}${path}`, { ...options, headers });
  if (res.status === 204) return null;
  const data = await res.json().catch(() => ({}));
  if (!res.ok) throw new Error(data.error || res.statusText);
  return data;
}

async function showTimerView() {
  loginView.hidden = true;
  timerView.hidden = false;
  await refreshTasks();
  await refreshTimer();
}

document.getElementById('login-btn').addEventListener('click', async () => {
  try {
    const server = document.getElementById('server').value.replace(/\/$/, '');
    const email = document.getElementById('email').value;
    const password = document.getElementById('password').value;
    const res = await fetch(`${server}/api/time-track/login`, {
      method: 'POST',
      headers: { 'Content-Type': 'application/json' },
      body: JSON.stringify({ username: email, password, clientType: 'extension' }),
    });
    const data = await res.json();
    if (!res.ok) throw new Error(data.error || 'Login failed');
    await chrome.storage.local.set({ server, token: data.token, email });
    await showTimerView();
    setStatus('Logged in');
  } catch (e) {
    setStatus(e.message);
  }
});

async function refreshTasks() {
  const data = await api('/api/time-track/tasks');
  const select = document.getElementById('tasks');
  select.innerHTML = '';
  for (const task of data.tasks || []) {
    const opt = document.createElement('option');
    opt.value = task.id;
    opt.textContent = task.title;
    select.appendChild(opt);
  }
}

async function refreshTimer() {
  try {
    const data = await api('/api/time-track/timer');
    document.getElementById('active-task').textContent = data?.timer
      ? `Active: ${data.timer.taskTitle}`
      : 'No active timer';
  } catch {
    document.getElementById('active-task').textContent = 'No active timer';
  }
}

document.getElementById('start-btn').addEventListener('click', async () => {
  try {
    const taskId = document.getElementById('tasks').value;
    await api('/api/time-track/timer/start', {
      method: 'POST',
      body: JSON.stringify({ taskId, clientType: 'extension' }),
    });
    await refreshTimer();
    setStatus('Timer started');
  } catch (e) {
    setStatus(e.message);
  }
});

document.getElementById('stop-btn').addEventListener('click', async () => {
  try {
    await api('/api/time-track/timer/stop', { method: 'POST', body: '{}' });
    await refreshTimer();
    setStatus('Timer stopped');
  } catch (e) {
    setStatus(e.message);
  }
});

document.getElementById('logout-btn').addEventListener('click', async () => {
  try {
    await api('/api/time-track/logout', { method: 'POST', body: '{}' });
  } catch (_) { /* ignore */ }
  await chrome.storage.local.remove(['token']);
  timerView.hidden = true;
  loginView.hidden = false;
  setStatus('Logged out');
});

getConfig().then(({ token }) => {
  if (token) showTimerView();
});
