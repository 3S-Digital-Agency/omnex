import { cn, initials } from '../utils';

describe('cn', () => {
  it('joins truthy values', () => {
    expect(cn('a', false, 'b', null, undefined, 'c')).toBe('a b c');
  });
});

describe('initials', () => {
  it('returns the first letters of up to two words', () => {
    expect(initials('Ada Lovelace')).toBe('AL');
    expect(initials('Grace')).toBe('G');
    expect(initials('')).toBe('');
  });
});
