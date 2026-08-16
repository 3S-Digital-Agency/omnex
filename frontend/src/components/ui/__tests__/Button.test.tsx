import { render, screen } from '@testing-library/react';
import userEvent from '@testing-library/user-event';
import { Button } from '../Button';

it('renders children and handles clicks', async () => {
  const onClick = vi.fn();
  render(<Button onClick={onClick}>Create</Button>);

  const button = screen.getByRole('button', { name: /create/i });
  await userEvent.click(button);

  expect(onClick).toHaveBeenCalledOnce();
});

it('disables the button while loading', () => {
  render(<Button loading>Save</Button>);
  expect(screen.getByRole('button')).toBeDisabled();
});
