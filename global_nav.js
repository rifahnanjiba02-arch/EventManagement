async function updateGlobalNav() {
  const navs = document.querySelectorAll('[data-global-nav]');

  if (!navs.length) {
    return;
  }

  let session = { role: null };

  try {
    const response = await fetch('get_session.php', {
      headers: {
        Accept: 'application/json'
      }
    });

    if (response.ok) {
      session = await response.json();
    }
  } catch (error) {
    console.error('Failed to load session for global navigation:', error);
  }

  const isLoggedIn = session.role === 'attendee' || session.role === 'organizer';
  const dashboardHref = session.role === 'organizer' ? 'organizer_profile.php' : 'attendee_profile.php';
  const links = isLoggedIn
    ? [
        ['index.html', 'Home', 'btn btn-secondary'],
        ['events.html', 'Events', 'btn btn-secondary'],
        [dashboardHref, 'Dashboard', 'btn btn-secondary'],
        ['logout.php', 'Log Out', 'btn btn-primary']
      ]
    : [
        ['index.html', 'Home', 'btn btn-secondary'],
        ['events.html', 'Events', 'btn btn-secondary'],
        ['login.php', 'Login', 'btn btn-secondary'],
        ['register.php', 'Create Account', 'btn btn-primary']
      ];

  navs.forEach((nav) => {
    nav.innerHTML = links
      .map(([href, label, className]) => `<a href="${href}" class="${className}">${label}</a>`)
      .join('');
  });
}

document.addEventListener('DOMContentLoaded', updateGlobalNav);
