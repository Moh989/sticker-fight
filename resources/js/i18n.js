// Arabic is the source language, so only English pages download the dictionary.
const en = document.documentElement.lang === 'en' ? (await import('../../lang/en.json')).default : {};
export const t = message => en[message] ?? message;
