import en from '../../lang/en.json';
export const t=message=>document.documentElement.lang==='en'?(en[message]??message):message;
