import { invoke } from '@tauri-apps/api/core';

const storage = {
  async get(key) {
    return localStorage.getItem(key);
  },
  async set(key, value) {
    localStorage.setItem(key, value);
  },
};

const IDLE_THRESHOLD_SECONDS = Number(import.meta.env.VITE_IDLE_THRESHOLD_SECONDS || 300);

async function getIdleSeconds() {
  try {
    return await invoke('get_idle_seconds');
  } catch {
    return 0;
  }
}

async function api(path, options = {}) {
  const server = (await storage.get('server')) || 'http://localhost:8024';
  const token = await storage.get('token');
  const headers = { 'Content-Type': 'application/json', ...(options.headers || {}) };
  if (token) headers.Authorization = `Bearer ${token}`;
  const res = await fetch(`${server}${path}`, { ...options, headers });
  if (res.status === 204) return null;
  const data = await res.json().catch(() => ({}));
  if (!res.ok) throw new Error(data.error || res.statusText);
  return data;
}

function setStatus(message) {
  document.getElementById('status').textContent = message;
}

document.getElementById('login').addEventListener('click', async () => {
  try {
    const server = document.getElementById('server').value.replace(/\/$/, '');
    await storage.set('server', server);
    const res = await fetch(`${server}/api/time-track/login`, {
      method: 'POST',
      headers: { 'Content-Type': 'application/json' },
      body: JSON.stringify({
        username: document.getElementById('email').value,
        password: document.getElementById('password').value,
        clientType: 'desktop',
      }),
    });
    const data = await res.json();
    if (!res.ok) {
      setStatus(data.error || 'Login failed');
      return;
    }
    await storage.set('token', data.token);
    setStatus('Logged in');
    await refresh();
  } catch (error) {
    setStatus(error.message);
  }
});

async function refresh() {
  const tasks = await api('/api/time-track/tasks');
  const select = document.getElementById('tasks');
  select.innerHTML = '';
  for (const task of tasks.tasks || []) {
    const opt = document.createElement('option');
    opt.value = task.id;
    opt.textContent = task.title;
    select.appendChild(opt);
  }
  try {
    const timer = await api('/api/time-track/timer');
    document.getElementById('timer').textContent = timer?.timer
      ? `Active: ${timer.timer.taskTitle}`
      : 'No active timer';
  } catch {
    document.getElementById('timer').textContent = 'No active timer';
  }
}

document.getElementById('start').addEventListener('click', async () => {
  await api('/api/time-track/timer/start', {
    method: 'POST',
    body: JSON.stringify({ taskId: document.getElementById('tasks').value, clientType: 'desktop' }),
  });
  await refresh();
});

document.getElementById('stop').addEventListener('click', async () => {
  await api('/api/time-track/timer/stop', { method: 'POST', body: '{}' });
  await refresh();
});

setInterval(async () => {
  if (!(await storage.get('token'))) return;
  try {
    const idleSeconds = await getIdleSeconds();
    const isIdle = idleSeconds >= IDLE_THRESHOLD_SECONDS;
    await api('/api/time-track/heartbeat', {
      method: 'POST',
      body: JSON.stringify({ isIdle }),
    });
    if (isIdle) {
      setStatus(`Idle (${idleSeconds}s)`);
    }
  } catch {
    /* ignore heartbeat errors */
  }
}, 30000);

if (await storage.get('token')) {
  refresh().catch((error) => setStatus(error.message));
}
