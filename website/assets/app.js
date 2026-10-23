const menu = document.querySelector('.menu');
const navigation = document.querySelector('#navigation');
const mobile = window.matchMedia('(max-width: 700px)');
function layout() {
  menu.hidden = !mobile.matches;
  navigation.hidden = mobile.matches;
  menu.setAttribute('aria-expanded', 'false');
}
menu.addEventListener('click', () => {
  navigation.hidden = !navigation.hidden;
  menu.setAttribute('aria-expanded', String(!navigation.hidden));
  menu.setAttribute('aria-label', navigation.hidden ? 'Open navigation' : 'Close navigation');
});
document.addEventListener('keydown', (event) => {
  if (event.key === 'Escape' && mobile.matches && !navigation.hidden) {
    navigation.hidden = true;
    menu.setAttribute('aria-expanded', 'false');
    menu.focus();
  }
});
mobile.addEventListener('change', layout);
layout();